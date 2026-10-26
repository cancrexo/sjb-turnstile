<?php
/**
 * Plugin Name: SJB Turnstile
 * Plugin URI: https://www.sjbdixital.es
 * Description: Cloudflare Turnstile en el login, el registro, la recuperación de contraseña y los comentarios.
 * Version: 1.0.0
 * Author: Daniel "Cancrexo" Prol
 * Author URI: https://www.sjbdixital.es
 * Requires at least: 6.0
 * Requires PHP: 8.3
 * Text Domain: sjb-turnstile
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package sjb-turnstile
 * @author  Daniel "Cancrexo" Prol <cancrexo@gmail.com>
 */

defined( 'ABSPATH' ) || exit;

register_activation_hook( __FILE__, array( 'SJB_Turnstile', 'activate' ) );
register_uninstall_hook( __FILE__, array( 'SJB_Turnstile', 'uninstall' ) );
add_action( 'plugins_loaded', array( 'SJB_Turnstile', 'init' ) );

/**
 * Cloudflare Turnstile en los formularios públicos de WordPress.
 */
class SJB_Turnstile {

    /** @var string */
    public const SLUG = 'sjb-turnstile';

    /** Acción de admin-ajax.php. Una sola para todo el plugin. */
    private const AJAX_ACTION = 'sjb_turnstile';

    /** @var string */
    public const VERSION = '1.0.0';

    /** Fecha del commit de esta versión. */
    private const RELEASED_ON = '20/10/2026';

    /** @var string */
    private const OPTION = 'sjb_turnstile';

    /** Marca de que la tabla de intentos ya existe. */
    private const DB_OPTION = 'sjb_turnstile_db';

    /** @var string */
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** Intentos por página en la pestaña Configuración. */
    private const LOG_LIMIT = 20;

    /** @var self|null */
    protected static $instance = null;

    /**
     * Punto de entrada del singleton.
     */
    public static function init(): self {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Crea la tabla de intentos si todavía no existe.
     */
    public static function activate(): void {
        self::create_table();
    }

    /**
     * Borra los ajustes. La tabla de intentos solo cae si el interruptor está encendido.
     */
    public static function uninstall(): void {
        $stored = get_option( self::OPTION, array() );
        if ( is_array( $stored ) && isset( $stored['drop_log'] ) && '1' === (string) $stored['drop_log'] ) {
            global $wpdb;
            $table = self::log_table();
            $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
        }

        delete_option( self::OPTION );
        delete_option( self::DB_OPTION );
    }

    /**
     * Engancha formularios, validación y la pantalla de ajustes.
     */
    public function __construct() {
        if ( ! self::check_requirements() ) {
            return;
        }

        load_plugin_textdomain( self::SLUG, false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

        add_action( 'login_form', array( $this, 'render_widget' ) );
        add_action( 'register_form', array( $this, 'render_widget' ) );
        add_action( 'lostpassword_form', array( $this, 'render_widget' ) );
        add_filter( 'comment_form_submit_field', array( $this, 'filter_comment_submit_field' ), 10, 2 );

        add_filter( 'authenticate', array( $this, 'guard_login' ), 100, 3 );
        add_filter( 'registration_errors', array( $this, 'guard_registration' ), 10, 3 );
        add_action( 'lostpassword_post', array( $this, 'guard_lostpassword' ), 10, 2 );
        add_filter( 'preprocess_comment', array( $this, 'guard_comment' ) );

        add_action( 'init', array( $this, 'maybe_install' ) );
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
    }

    /**
     * Comprueba PHP 8.3 o superior.
     */
    private static function check_requirements(): bool {
        if ( version_compare( PHP_VERSION, '8.3', '>=' ) ) {
            return true;
        }

        add_action(
            'admin_notices',
            static function (): void {
                echo '<div class="notice notice-error"><p>';
                esc_html_e( 'SJB Turnstile requiere PHP 8.3 o superior.', 'sjb-turnstile' );
                echo '</p></div>';
            }
        );

        return false;
    }

    /**
     * Formularios que pueden llevar Turnstile. Clave interna => etiqueta.
     *
     * @return array<string,string>
     */
    private function forms(): array {
        return array(
            'login'        => __( 'Login', 'sjb-turnstile' ),
            'register'     => __( 'Registro', 'sjb-turnstile' ),
            'lostpassword' => __( 'Recuperar contraseña', 'sjb-turnstile' ),
            'comments'     => __( 'Comentarios', 'sjb-turnstile' ),
        );
    }

    /**
     * Claves y interruptores. Los formularios salen encendidos si aún no hay valor guardado.
     *
     * @return array<string,string>
     */
    private function options(): array {
        $stored = get_option( self::OPTION, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }

        $options = array(
            'site_key'   => isset( $stored['site_key'] ) ? (string) $stored['site_key'] : '',
            'secret_key' => isset( $stored['secret_key'] ) ? (string) $stored['secret_key'] : '',
        );

        foreach ( array_keys( $this->forms() ) as $form ) {
            $options[ $form ] = ( isset( $stored[ $form ] ) && '0' === (string) $stored[ $form ] ) ? '0' : '1';
        }

        $options['log']      = ( isset( $stored['log'] ) && '1' === (string) $stored['log'] ) ? '1' : '0';
        $options['drop_log'] = ( isset( $stored['drop_log'] ) && '1' === (string) $stored['drop_log'] ) ? '1' : '0';

        return $options;
    }

    /**
     * Turnstile solo actúa cuando las dos claves están guardadas.
     */
    private function is_configured(): bool {
        $options = $this->options();

        return '' !== $options['site_key'] && '' !== $options['secret_key'];
    }

    /**
     * El formulario tiene el interruptor encendido.
     */
    private function is_form_enabled( string $form ): bool {
        $options = $this->options();

        return isset( $options[ $form ] ) && '1' === $options[ $form ];
    }

    /**
     * Script oficial. Una sola vez, en el pie, después del widget.
     */
    private function enqueue_api(): void {
        if ( wp_script_is( self::SLUG, 'enqueued' ) ) {
            return;
        }

        wp_enqueue_script(
            self::SLUG,
            'https://challenges.cloudflare.com/turnstile/v0/api.js',
            array(),
            null,
            true
        );

        wp_enqueue_style(
            self::SLUG,
            plugins_url( 'assets/css/front.css', __FILE__ ),
            array(),
            (string) filemtime( __DIR__ . '/assets/css/front.css' )
        );
    }

    /**
     * HTML del widget. Vacío hasta que haya site key.
     */
    private function widget_markup(): string {
        if ( ! $this->is_configured() ) {
            return '';
        }

        $this->enqueue_api();

        return '<div class="sjb-turnstile">'
            . '<div class="cf-turnstile" data-sitekey="' . esc_attr( $this->options()['site_key'] ) . '" data-theme="auto" data-size="normal"></div>'
            . '</div>';
    }

    /**
     * Pinta el widget en login, registro y recuperar contraseña.
     */
    public function render_widget(): void {
        $forms = array(
            'login_form'        => 'login',
            'register_form'     => 'register',
            'lostpassword_form' => 'lostpassword',
        );
        $hook = current_action();
        $form = isset( $forms[ $hook ] ) ? $forms[ $hook ] : '';

        if ( '' === $form || ! $this->is_form_enabled( $form ) ) {
            return;
        }

        echo $this->widget_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- widget_markup() ya escapa.
    }

    /**
     * Coloca el widget encima del botón de enviar, solo para visitantes.
     *
     * @param string              $submit_field HTML del botón.
     * @param array<string,mixed> $args         Argumentos de comment_form().
     */
    public function filter_comment_submit_field( string $submit_field, array $args ): string {
        unset( $args );

        if ( is_user_logged_in() || ! $this->is_form_enabled( 'comments' ) ) {
            return $submit_field;
        }

        return $this->widget_markup() . $submit_field;
    }

    /**
     * Mensaje único cuando la verificación no pasa.
     */
    private function failure_message(): string {
        return __( 'No se ha podido verificar que eres humano. Vuelve a intentarlo.', 'sjb-turnstile' );
    }

    /**
     * Comprueba el token del POST contra Cloudflare.
     *
     * @param string $secret Secret key a usar. Vacía: la que está guardada.
     */
    private function token_is_valid( string $secret = '' ): bool {
        $token = isset( $_POST['cf-turnstile-response'] )
            ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) )
            : '';

        if ( '' === $token ) {
            return false;
        }

        if ( '' === $secret ) {
            $secret = $this->options()['secret_key'];
        }

        $response = wp_remote_post(
            self::VERIFY_URL,
            array(
                'timeout' => 8,
                'body'    => array(
                    'secret'   => $secret,
                    'response' => $token,
                    'remoteip' => $this->client_ip(),
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        return is_array( $body ) && ! empty( $body['success'] );
    }

    /**
     * Login de wp-login.php y del shortcode de The7. Ambos envían el campo log.
     *
     * @param WP_User|WP_Error|null $user     Usuario ya resuelto, o error previo.
     * @param string                $username Nombre enviado.
     * @param string                $password Contraseña enviada.
     * @return WP_User|WP_Error|null
     */
    public function guard_login( $user, $username, $password ) {
        unset( $username, $password );

        if ( ! isset( $_POST['log'] ) || ! $this->blocks( 'login' ) ) {
            return $user;
        }

        return new WP_Error( 'sjb_turnstile', $this->failure_message() );
    }

    /**
     * Registro de usuarios.
     *
     * @param WP_Error $errors               Errores acumulados.
     * @param string   $sanitized_user_login Usuario saneado.
     * @param string   $user_email           Correo enviado.
     */
    public function guard_registration( WP_Error $errors, $sanitized_user_login, $user_email ): WP_Error {
        unset( $sanitized_user_login, $user_email );

        if ( $this->blocks( 'register' ) ) {
            $errors->add( 'sjb_turnstile', $this->failure_message() );
        }

        return $errors;
    }

    /**
     * Recuperación de contraseña.
     *
     * @param WP_Error $errors    Errores acumulados. Se modifican por referencia de objeto.
     * @param mixed    $user_data Usuario encontrado, o false.
     */
    public function guard_lostpassword( WP_Error $errors, $user_data ): void {
        unset( $user_data );

        if ( $this->blocks( 'lostpassword' ) ) {
            $errors->add( 'sjb_turnstile', $this->failure_message() );
        }
    }

    /**
     * Comentarios de visitantes. Pingbacks y usuarios con sesión no pasan por Turnstile.
     *
     * @param array<string,mixed> $commentdata Datos del comentario.
     * @return array<string,mixed>
     */
    public function guard_comment( array $commentdata ): array {
        if ( is_user_logged_in() ) {
            return $commentdata;
        }

        $type = isset( $commentdata['comment_type'] ) ? (string) $commentdata['comment_type'] : '';
        if ( in_array( $type, array( 'pingback', 'trackback' ), true ) ) {
            return $commentdata;
        }

        if ( ! $this->blocks( 'comments' ) ) {
            return $commentdata;
        }

        wp_die(
            esc_html( $this->failure_message() ),
            esc_html__( 'Comentario rechazado', 'sjb-turnstile' ),
            array(
                'response'  => 403,
                'back_link' => true,
            )
        );

        return $commentdata;
    }

    /**
     * Hay que rechazar el envío. Si el registro está encendido, deja constancia.
     */
    private function blocks( string $form ): bool {
        if ( ! $this->is_configured() || ! $this->is_form_enabled( $form ) ) {
            return false;
        }

        $valid = $this->token_is_valid();
        $this->log_attempt( $form, $valid );

        return ! $valid;
    }

    /**
     * IP del visitante, como mucho 45 caracteres (IPv6).
     */
    private function client_ip(): string {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        if ( strlen( $ip ) > 45 ) {
            $ip = substr( $ip, 0, 45 );
        }

        return $ip;
    }

    /**
     * Página del intento: la URL del comentario, o el formulario y su referer.
     */
    private function attempt_page( string $form ): string {
        $labels = $this->forms();
        $label  = isset( $labels[ $form ] ) ? $labels[ $form ] : $form;

        if ( 'comments' === $form ) {
            $post_id = isset( $_POST['comment_post_ID'] ) ? absint( $_POST['comment_post_ID'] ) : 0;
            $url     = $post_id ? (string) get_permalink( $post_id ) : '';
            $page    = '' !== $url ? $url : $label;
        } else {
            $referer = wp_get_referer();
            $page    = ( is_string( $referer ) && '' !== $referer ) ? $label . ' — ' . $referer : $label;
        }

        if ( strlen( $page ) > 512 ) {
            $page = substr( $page, 0, 512 );
        }

        return $page;
    }

    /**
     * Guarda un intento si el interruptor de Configuración está encendido.
     */
    private function log_attempt( string $form, bool $ok ): void {
        if ( '1' !== $this->options()['log'] ) {
            return;
        }

        if ( '1' !== get_option( self::DB_OPTION, '' ) ) {
            self::create_table();
        }

        global $wpdb;
        $wpdb->insert(
            self::log_table(),
            array(
                'created_at' => current_time( 'mysql' ),
                'ip'         => $this->client_ip(),
                'result'     => $ok ? 'ok' : 'fail',
                'page'       => $this->attempt_page( $form ),
            ),
            array( '%s', '%s', '%s', '%s' )
        );
    }

    /**
     * Nombre de la tabla de intentos.
     */
    private static function log_table(): string {
        global $wpdb;

        return $wpdb->prefix . 'sjb_turnstile_log';
    }

    /**
     * Crea la tabla con dbDelta. Si ya existe, no la vacía.
     */
    public static function create_table(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = self::log_table();
        $charset = $wpdb->get_charset_collate();
        $sql     = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            ip varchar(45) NOT NULL DEFAULT '',
            result varchar(16) NOT NULL DEFAULT '',
            page varchar(512) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY created_at (created_at)
        ) {$charset};";

        dbDelta( $sql );

        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        if ( $found === $table ) {
            update_option( self::DB_OPTION, '1', true );
        }
    }

    /**
     * En instalaciones ya activas, crea la tabla la primera vez que falta.
     */
    public function maybe_install(): void {
        if ( '1' === get_option( self::DB_OPTION, '' ) ) {
            return;
        }

        self::create_table();
    }

    /**
     * Tamaño de página permitido: 20, 50 o 100.
     */
    private function per_page( $value ): int {
        $n = absint( $value );

        return in_array( $n, array( 20, 50, 100 ), true ) ? $n : self::LOG_LIMIT;
    }

    /**
     * Una página de intentos, los más recientes primero.
     *
     * @return array{rows:array<int,array<string,string>>,total:int,page:int,per_page:int,pages:int}
     */
    private function recent_logs( int $page = 1, int $per_page = self::LOG_LIMIT ): array {
        global $wpdb;

        $per_page = $this->per_page( $per_page );
        $empty    = array(
            'rows'        => array(),
            'total'       => 0,
            'page'        => 1,
            'per_page'    => $per_page,
            'pages'       => 1,
            'total_label' => __( 'Total 0 registros.', 'sjb-turnstile' ),
        );

        if ( '1' !== get_option( self::DB_OPTION, '' ) ) {
            return $empty;
        }

        $table = self::log_table();
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
        $pages = max( 1, (int) ceil( $total / $per_page ) );
        $page  = min( max( 1, $page ), $pages );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT created_at, ip, result, page FROM `{$table}` ORDER BY id DESC LIMIT %d OFFSET %d",
                $per_page,
                ( $page - 1 ) * $per_page
            ),
            ARRAY_A
        );

        return array(
            'rows'        => is_array( $rows ) ? $rows : array(),
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'pages'       => $pages,
            'total_label' => sprintf(
                /* translators: %d: número de intentos. */
                _n( 'Total %d registro.', 'Total %d registros.', $total, 'sjb-turnstile' ),
                $total
            ),
        );
    }

    /**
     * Filas de la tabla de intentos.
     *
     * @param array<int,array<string,string>> $rows
     */
    private function log_rows_html( array $rows ): string {
        $html = '';
        foreach ( $rows as $row ) {
            $result = 'ok' === ( $row['result'] ?? '' )
                ? __( 'Aceptado', 'sjb-turnstile' )
                : __( 'Rechazado', 'sjb-turnstile' );
            $html  .= '<tr>';
            $html  .= '<td>' . esc_html( mysql2date( 'd/m/Y H:i', (string) $row['created_at'] ) ) . '</td>';
            $html  .= '<td>' . esc_html( (string) $row['ip'] ) . '</td>';
            $html  .= '<td>' . esc_html( $result ) . '</td>';
            $html  .= '<td>' . esc_html( (string) $row['page'] ) . '</td>';
            $html  .= '</tr>';
        }

        return $html;
    }

    /**
     * Números de página visibles: ventana y puntos suspensivos.
     *
     * @return array<int,int|string>
     */
    private function pager_items( int $page, int $pages ): array {
        if ( $pages <= 7 ) {
            return range( 1, $pages );
        }

        $start = 2;
        $end   = 7;
        if ( $page > 4 && $page < $pages - 3 ) {
            $start = $page - 2;
            $end   = $page + 2;
        } elseif ( $page >= $pages - 3 ) {
            $start = $pages - 6;
            $end   = $pages - 1;
        }

        $items = array( 1 );
        if ( $start > 2 ) {
            $items[] = '…';
        }
        for ( $i = $start; $i <= $end; $i++ ) {
            $items[] = $i;
        }
        if ( $end < $pages - 1 ) {
            $items[] = '…';
        }
        $items[] = $pages;

        return $items;
    }

    /**
     * Paginador: anterior, números, siguiente y tamaño de página.
     *
     * @param array{page:int,pages:int,per_page:int,total:int} $log
     */
    private function log_pager_html( array $log ): string {
        if ( $log['total'] < 1 ) {
            return '';
        }

        $page  = (int) $log['page'];
        $pages = (int) $log['pages'];
        $html  = '';

        $prev = $page > 1 ? $page - 1 : 0;
        $next = $page < $pages ? $page + 1 : 0;
        $html .= '<button type="button" class="sjb-pager-btn" data-page="' . $prev . '"' . ( $prev ? '' : ' disabled' ) . '>&laquo;</button>';

        foreach ( $this->pager_items( $page, $pages ) as $item ) {
            if ( '…' === $item ) {
                $html .= '<span class="sjb-pager-gap">&hellip;</span>';
                continue;
            }
            $current = ( (int) $item === $page ) ? ' is-current' : '';
            $html   .= '<button type="button" class="sjb-pager-btn' . $current . '" data-page="' . (int) $item . '">' . (int) $item . '</button>';
        }

        $html .= '<button type="button" class="sjb-pager-btn" data-page="' . $next . '"' . ( $next ? '' : ' disabled' ) . '>&raquo;</button>';
        $html .= '<select id="sjb-turnstile-per-page" aria-label="' . esc_attr__( 'Registros por página', 'sjb-turnstile' ) . '">';
        foreach ( array( 20, 50, 100 ) as $size ) {
            $html .= '<option value="' . $size . '"' . selected( $log['per_page'], $size, false ) . '>' . $size . '</option>';
        }
        $html .= '</select>';

        return $html;
    }

    /**
     * Única rutina AJAX. El parámetro task elige la operación.
     */
    public function ajax(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'No tienes permisos para esta acción.', 'sjb-turnstile' ),
                    'level'   => 'error',
                ),
                403
            );
        }

        if ( ! check_ajax_referer( self::SLUG, 'nonce', false ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Sesión no válida. Recarga la página e inténtalo de nuevo.', 'sjb-turnstile' ),
                    'level'   => 'error',
                ),
                403
            );
        }

        $task    = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';
        $options = $this->options();

        if ( 'save_cloudflare' === $task ) {
            $options['site_key']   = isset( $_POST['site_key'] ) ? sanitize_text_field( wp_unslash( $_POST['site_key'] ) ) : '';
            $options['secret_key'] = isset( $_POST['secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['secret_key'] ) ) : '';
            update_option( self::OPTION, $options );

            if ( '' === $options['site_key'] || '' === $options['secret_key'] ) {
                wp_send_json_success(
                    array(
                        'message' => __( 'Guardado. Falta alguna clave: Turnstile no se aplicará hasta que estén las dos.', 'sjb-turnstile' ),
                        'level'   => 'warning',
                    )
                );
            }

            wp_send_json_success(
                array(
                    'message' => __( 'Claves guardadas.', 'sjb-turnstile' ),
                    'level'   => 'success',
                )
            );
        }

        if ( 'test_keys' === $task ) {
            $site   = isset( $_POST['site_key'] ) ? sanitize_text_field( wp_unslash( $_POST['site_key'] ) ) : '';
            $secret = isset( $_POST['secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['secret_key'] ) ) : '';

            if ( '' === $site || '' === $secret ) {
                wp_send_json_success(
                    array(
                        'message' => __( 'Escribe la site key y la secret key para probar.', 'sjb-turnstile' ),
                        'level'   => 'warning',
                    )
                );
            }

            if ( $this->token_is_valid( $secret ) ) {
                wp_send_json_success(
                    array(
                        'message' => __( 'Turnstile responde bien con estas claves.', 'sjb-turnstile' ),
                        'level'   => 'success',
                    )
                );
            }

            wp_send_json_error(
                array(
                    'message' => __( 'Cloudflare ha rechazado la prueba. Revisa las claves y que este dominio esté autorizado en el widget.', 'sjb-turnstile' ),
                    'level'   => 'error',
                )
            );
        }

        if ( 'save_visualizacion' === $task ) {
            foreach ( array_keys( $this->forms() ) as $form ) {
                $options[ $form ] = ( isset( $_POST[ $form ] ) && '1' === (string) wp_unslash( $_POST[ $form ] ) ) ? '1' : '0';
            }
            update_option( self::OPTION, $options );
            wp_send_json_success(
                array(
                    'message' => __( 'Visualización guardada.', 'sjb-turnstile' ),
                    'level'   => 'success',
                )
            );
        }

        if ( 'save_configuracion' === $task ) {
            $options['log']      = ( isset( $_POST['log'] ) && '1' === (string) wp_unslash( $_POST['log'] ) ) ? '1' : '0';
            $options['drop_log'] = ( isset( $_POST['drop_log'] ) && '1' === (string) wp_unslash( $_POST['drop_log'] ) ) ? '1' : '0';
            update_option( self::OPTION, $options );
            wp_send_json_success(
                array(
                    'message' => __( 'Configuración guardada.', 'sjb-turnstile' ),
                    'level'   => 'success',
                )
            );
        }

        if ( 'export_log' === $task ) {
            global $wpdb;

            if ( '1' !== get_option( self::DB_OPTION, '' ) ) {
                self::create_table();
            }

            $table = self::log_table();
            $rows  = $wpdb->get_results(
                "SELECT created_at, ip, result, page FROM `{$table}` ORDER BY id ASC",
                ARRAY_A
            );

            if ( ! is_array( $rows ) ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'No se pudo leer la tabla de registros.', 'sjb-turnstile' ),
                        'level'   => 'error',
                    )
                );
            }

            if ( array() === $rows ) {
                wp_send_json_success(
                    array(
                        'message' => __( 'No hay registros que exportar.', 'sjb-turnstile' ),
                        'level'   => 'warning',
                    )
                );
            }

            wp_send_json_success(
                array(
                    'message'  => __( 'Copia descargada.', 'sjb-turnstile' ),
                    'level'    => 'success',
                    'filename' => 'sjb-turnstile-' . wp_date( 'Ymd-His' ) . '.json',
                    'records'  => $rows,
                )
            );
        }

        if ( 'list_log' === $task ) {
            $page = isset( $_POST['page'] ) ? absint( wp_unslash( $_POST['page'] ) ) : 1;
            $per  = isset( $_POST['per_page'] ) ? absint( wp_unslash( $_POST['per_page'] ) ) : self::LOG_LIMIT;
            $log  = $this->recent_logs( $page, $per );

            wp_send_json_success(
                array(
                    'silent'      => true,
                    'body'        => $this->log_rows_html( $log['rows'] ),
                    'pager'       => $this->log_pager_html( $log ),
                    'total'       => $log['total'],
                    'total_label' => $log['total_label'],
                )
            );
        }

        if ( 'empty_log' === $task ) {
            global $wpdb;

            if ( '1' !== get_option( self::DB_OPTION, '' ) ) {
                self::create_table();
            }

            $table = self::log_table();
            $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
            if ( 0 === $count ) {
                wp_send_json_success(
                    array(
                        'message' => __( 'La tabla ya estaba vacía.', 'sjb-turnstile' ),
                        'level'   => 'warning',
                    )
                );
            }

            $deleted = $wpdb->query( "DELETE FROM `{$table}`" );
            if ( false === $deleted ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'No se pudieron borrar los registros.', 'sjb-turnstile' ),
                        'level'   => 'error',
                    )
                );
            }

            wp_send_json_success(
                array(
                    'message' => __( 'Registros eliminados.', 'sjb-turnstile' ),
                    'level'   => 'success',
                )
            );
        }

        wp_send_json_error(
            array(
                'message' => __( 'Acción no reconocida.', 'sjb-turnstile' ),
                'level'   => 'error',
            )
        );
    }

    /**
     * CSS y JavaScript de la pantalla de ajustes.
     *
     * @param string $hook Hook de la pantalla actual.
     */
    public function enqueue_admin_assets( string $hook ): void {
        if ( 'settings_page_' . self::SLUG !== $hook ) {
            return;
        }

        wp_enqueue_style(
            self::SLUG . '-admin',
            plugins_url( 'assets/css/admin.css', __FILE__ ),
            array(),
            (string) filemtime( __DIR__ . '/assets/css/admin.css' )
        );

        wp_enqueue_script(
            self::SLUG . '-admin',
            plugins_url( 'assets/js/admin.js', __FILE__ ),
            array(),
            (string) filemtime( __DIR__ . '/assets/js/admin.js' ),
            true
        );

        wp_localize_script(
            self::SLUG . '-admin',
            'sjbTurnstile',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'action'  => self::AJAX_ACTION,
                'nonce'   => wp_create_nonce( self::SLUG ),
                'i18n'    => array(
                    'network'      => __( 'No se ha podido completar la petición.', 'sjb-turnstile' ),
                    'confirmEmpty' => __( '¿Vaciar todos los registros? Esta acción no se puede deshacer.', 'sjb-turnstile' ),
                    'testKeys'     => __( 'Escribe la site key y la secret key para probar.', 'sjb-turnstile' ),
                    'testWidget'   => __( 'El widget no ha podido cargarse. Revisa la site key y que este dominio esté autorizado en Cloudflare.', 'sjb-turnstile' ),
                ),
            )
        );
    }

    /**
     * Entrada propia bajo Ajustes.
     */
    public function add_admin_menu(): void {
        add_options_page(
            'SJB Turnstile',
            'SJB Turnstile',
            'manage_options',
            self::SLUG,
            array( $this, 'render_admin_page' )
        );
    }

    /**
     * Enlace «Ajustes» en la fila del plugin.
     *
     * @param array<int,string> $links Enlaces actuales.
     * @return array<int,string>
     */
    public function plugin_action_links( array $links ): array {
        $url = admin_url( 'options-general.php?page=' . self::SLUG );
        array_unshift(
            $links,
            '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Ajustes', 'sjb-turnstile' ) . '</a>'
        );

        return $links;
    }

    /**
     * Botón de guardar con el icono delante del texto.
     */
    private function render_save_button(): void {
        echo '<p class="submit"><button type="submit" class="button button-primary">';
        echo '<span class="dashicons dashicons-cloud-saved" aria-hidden="true"></span>';
        echo esc_html__( 'Guardar cambios', 'sjb-turnstile' );
        echo '</button></p>';
    }

    /**
     * Pantalla de ajustes: Cloudflare, Visualización, Configuración y Acerca de.
     */
    public function render_admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $tabs = array(
            'cloudflare'    => __( 'Cloudflare', 'sjb-turnstile' ),
            'visualizacion' => __( 'Visualización', 'sjb-turnstile' ),
            'configuracion' => __( 'Configuración', 'sjb-turnstile' ),
            'acerca-de'     => __( 'Acerca de', 'sjb-turnstile' ),
        );
        $tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'cloudflare';
        if ( 'proximamente' === $tab || 'info' === $tab ) {
            $tab = 'configuracion';
        }
        if ( ! isset( $tabs[ $tab ] ) ) {
            $tab = 'cloudflare';
        }

        $base    = admin_url( 'options-general.php?page=' . self::SLUG );
        $options = $this->options();
        $log     = ( 'configuracion' === $tab ) ? $this->recent_logs() : array( 'rows' => array(), 'total' => 0 );
        ?>
        <div class="wrap">
            <h1>SJB Turnstile</h1>

            <nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Secciones de SJB Turnstile', 'sjb-turnstile' ); ?>">
                <?php foreach ( $tabs as $slug => $label ) : ?>
                    <a href="<?php echo esc_url( $base . '&tab=' . $slug ); ?>" class="nav-tab<?php echo $slug === $tab ? ' nav-tab-active' : ''; ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="sjb-turnstile-tab">
            <?php if ( 'cloudflare' === $tab ) : ?>
                <div id="sjb-turnstile-keys-notice" class="notice notice-warning inline"<?php echo $this->is_configured() ? ' hidden' : ''; ?>>
                    <p><?php esc_html_e( 'Turnstile no se aplicará hasta que guardes la site key y la secret key.', 'sjb-turnstile' ); ?></p>
                </div>

                <form method="post" action="#" class="sjb-turnstile-form" data-task="save_cloudflare">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">
                                <label for="sjb-turnstile-site-key"><?php esc_html_e( 'Site key', 'sjb-turnstile' ); ?></label>
                            </th>
                            <td>
                                <input
                                    type="text"
                                    class="regular-text"
                                    id="sjb-turnstile-site-key"
                                    name="site_key"
                                    value="<?php echo esc_attr( $options['site_key'] ); ?>"
                                    autocomplete="off"
                                >
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="sjb-turnstile-secret-key"><?php esc_html_e( 'Secret key', 'sjb-turnstile' ); ?></label>
                            </th>
                            <td>
                                <input
                                    type="text"
                                    class="regular-text"
                                    id="sjb-turnstile-secret-key"
                                    name="secret_key"
                                    value="<?php echo esc_attr( $options['secret_key'] ); ?>"
                                    autocomplete="off"
                                >
                                <p class="description">
                                    <?php esc_html_e( 'Las dos claves salen del widget Turnstile en el panel de Cloudflare. El modo (Managed, invisible, etc.) se elige allí, al crear el widget.', 'sjb-turnstile' ); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit sjb-turnstile-key-actions">
                        <button type="button" class="button" id="sjb-turnstile-test">
                            <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                            <?php esc_html_e( 'Probar', 'sjb-turnstile' ); ?>
                        </button>
                        <button type="submit" class="button button-primary">
                            <span class="dashicons dashicons-cloud-saved" aria-hidden="true"></span>
                            <?php esc_html_e( 'Guardar cambios', 'sjb-turnstile' ); ?>
                        </button>
                    </p>
                    <div id="sjb-turnstile-test-box" class="sjb-turnstile-test" hidden>
                        <p class="description"><?php esc_html_e( 'Completa la comprobación. No se guardan las claves.', 'sjb-turnstile' ); ?></p>
                        <div id="sjb-turnstile-test-widget"></div>
                    </div>
                </form>
            <?php elseif ( 'visualizacion' === $tab ) : ?>
                <p class="description">
                    <?php esc_html_e( 'Con el interruptor apagado, esa pantalla no muestra Turnstile ni lo exige al enviar.', 'sjb-turnstile' ); ?>
                </p>
                <form method="post" action="#" class="sjb-turnstile-form" data-task="save_visualizacion">
                    <table class="form-table" role="presentation">
                        <?php foreach ( $this->forms() as $key => $label ) : ?>
                            <tr>
                                <th scope="row">
                                    <label for="sjb-turnstile-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
                                </th>
                                <td>
                                    <span class="sjb-switch">
                                        <input
                                            type="checkbox"
                                            id="sjb-turnstile-<?php echo esc_attr( $key ); ?>"
                                            name="<?php echo esc_attr( $key ); ?>"
                                            value="1"
                                            <?php checked( $options[ $key ], '1' ); ?>
                                        >
                                        <span class="sjb-switch-track" aria-hidden="true"></span>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                    <?php $this->render_save_button(); ?>
                </form>
            <?php elseif ( 'configuracion' === $tab ) : ?>
                <p class="description">
                    <?php esc_html_e( 'Con el registro apagado no se guarda ningún intento.', 'sjb-turnstile' ); ?>
                </p>
                <form method="post" action="#" class="sjb-turnstile-form" data-task="save_configuracion">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">
                                <label for="sjb-turnstile-log"><?php esc_html_e( 'Registrar intentos', 'sjb-turnstile' ); ?></label>
                            </th>
                            <td>
                                <span class="sjb-switch">
                                    <input type="checkbox" id="sjb-turnstile-log" name="log" value="1" <?php checked( $options['log'], '1' ); ?>>
                                    <span class="sjb-switch-track" aria-hidden="true"></span>
                                </span>
                                <p class="description"><?php esc_html_e( 'Guarda fecha y hora, IP, resultado y página de cada comprobación.', 'sjb-turnstile' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="sjb-turnstile-drop-log"><?php esc_html_e( 'Borrar la tabla al desinstalar', 'sjb-turnstile' ); ?></label>
                            </th>
                            <td>
                                <span class="sjb-switch">
                                    <input type="checkbox" id="sjb-turnstile-drop-log" name="drop_log" value="1" <?php checked( $options['drop_log'], '1' ); ?>>
                                    <span class="sjb-switch-track" aria-hidden="true"></span>
                                </span>
                                <p class="description"><?php esc_html_e( 'Si está apagado, la tabla se queda en la base de datos al desinstalar el plugin.', 'sjb-turnstile' ); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p class="sjb-turnstile-log-actions">
                        <button type="button" class="button" id="sjb-turnstile-export">
                            <span class="dashicons dashicons-download" aria-hidden="true"></span>
                            <?php esc_html_e( 'Exportar', 'sjb-turnstile' ); ?>
                        </button>
                        <button type="button" class="button" id="sjb-turnstile-empty">
                            <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                            <?php esc_html_e( 'Vaciar registros', 'sjb-turnstile' ); ?>
                        </button>
                    </p>
                    <?php $this->render_save_button(); ?>
                </form>
                <div class="sjb-turnstile-log">
                    <div class="sjb-log-loading" id="sjb-turnstile-log-loading" role="status" hidden>
                        <span class="sjb-log-spinner" aria-hidden="true"></span>
                        <span><?php esc_html_e( 'Espera, por favor…', 'sjb-turnstile' ); ?></span>
                    </div>
                    <p id="sjb-turnstile-log-empty"<?php echo $log['total'] > 0 ? ' hidden' : ''; ?>>
                        <?php esc_html_e( 'Todavía no hay intentos.', 'sjb-turnstile' ); ?>
                    </p>
                    <table class="widefat striped" id="sjb-turnstile-log-table"<?php echo $log['total'] > 0 ? '' : ' hidden'; ?>>
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Fecha y hora', 'sjb-turnstile' ); ?></th>
                                <th><?php esc_html_e( 'IP', 'sjb-turnstile' ); ?></th>
                                <th><?php esc_html_e( 'Resultado', 'sjb-turnstile' ); ?></th>
                                <th><?php esc_html_e( 'Página', 'sjb-turnstile' ); ?></th>
                            </tr>
                        </thead>
                        <tbody id="sjb-turnstile-log-body">
                            <?php echo $this->log_rows_html( $log['rows'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cada celda se escapa en log_rows_html(). ?>
                        </tbody>
                    </table>
                    <nav class="sjb-pager" id="sjb-turnstile-pager" aria-label="<?php esc_attr_e( 'Paginación de intentos', 'sjb-turnstile' ); ?>"<?php echo $log['total'] > 0 ? '' : ' hidden'; ?>>
                        <?php echo $this->log_pager_html( $log ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- el HTML se escapa en log_pager_html(). ?>
                    </nav>
                    <p class="sjb-pager-total" id="sjb-turnstile-log-total"<?php echo $log['total'] > 0 ? '' : ' hidden'; ?>>
                        <?php echo esc_html( $log['total_label'] ); ?>
                    </p>
                </div>
            <?php else : ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Autor', 'sjb-turnstile' ); ?></th>
                        <td>
                            Daniel &quot;Cancrexo&quot; Prol ·
                            <a href="mailto:cancrexo@gmail.com">cancrexo@gmail.com</a>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Versión', 'sjb-turnstile' ); ?></th>
                        <td><?php echo esc_html( self::VERSION ); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Fecha', 'sjb-turnstile' ); ?></th>
                        <td><?php echo esc_html( self::RELEASED_ON ); ?></td>
                    </tr>
                </table>
            <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
