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

    /** Intentos que se listan en la pestaña Info. */
    private const LOG_LIMIT = 100;

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
    }

    /**
     * HTML del widget. Vacío hasta que haya site key.
     */
    private function widget_markup(): string {
        if ( ! $this->is_configured() ) {
            return '';
        }

        $this->enqueue_api();

        return '<div class="sjb-turnstile" style="margin:1em 0;">'
            . '<div class="cf-turnstile" data-sitekey="' . esc_attr( $this->options()['site_key'] ) . '" data-theme="auto"></div>'
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
     */
    private function token_is_valid(): bool {
        $token = isset( $_POST['cf-turnstile-response'] )
            ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) )
            : '';

        if ( '' === $token ) {
            return false;
        }

        $response = wp_remote_post(
            self::VERIFY_URL,
            array(
                'timeout' => 8,
                'body'    => array(
                    'secret'   => $this->options()['secret_key'],
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
     * Guarda un intento si el interruptor de Info está encendido.
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
     * Últimos intentos para la pestaña Info.
     *
     * @return array{rows:array<int,array<string,string>>,total:int}
     */
    private function recent_logs(): array {
        global $wpdb;

        if ( '1' !== get_option( self::DB_OPTION, '' ) ) {
            return array(
                'rows'  => array(),
                'total' => 0,
            );
        }

        $table = self::log_table();
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT created_at, ip, result, page FROM `{$table}` ORDER BY id DESC LIMIT %d",
                self::LOG_LIMIT
            ),
            ARRAY_A
        );

        return array(
            'rows'  => is_array( $rows ) ? $rows : array(),
            'total' => $total,
        );
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

        if ( 'save_configuracion' === $task ) {
            foreach ( array_keys( $this->forms() ) as $form ) {
                $options[ $form ] = ( isset( $_POST[ $form ] ) && '1' === (string) wp_unslash( $_POST[ $form ] ) ) ? '1' : '0';
            }
            update_option( self::OPTION, $options );
            wp_send_json_success(
                array(
                    'message' => __( 'Configuración guardada.', 'sjb-turnstile' ),
                    'level'   => 'success',
                )
            );
        }

        if ( 'save_info' === $task ) {
            $options['log']      = ( isset( $_POST['log'] ) && '1' === (string) wp_unslash( $_POST['log'] ) ) ? '1' : '0';
            $options['drop_log'] = ( isset( $_POST['drop_log'] ) && '1' === (string) wp_unslash( $_POST['drop_log'] ) ) ? '1' : '0';
            update_option( self::OPTION, $options );
            wp_send_json_success(
                array(
                    'message' => __( 'Opciones de Info guardadas.', 'sjb-turnstile' ),
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
            self::VERSION
        );

        wp_enqueue_script(
            self::SLUG . '-admin',
            plugins_url( 'assets/js/admin.js', __FILE__ ),
            array(),
            self::VERSION,
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
     * Pantalla de ajustes: Cloudflare, Configuración, Info y Acerca de.
     */
    public function render_admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $tabs = array(
            'cloudflare'    => __( 'Cloudflare', 'sjb-turnstile' ),
            'configuracion' => __( 'Configuración', 'sjb-turnstile' ),
            'info'          => __( 'Info', 'sjb-turnstile' ),
            'acerca-de'     => __( 'Acerca de', 'sjb-turnstile' ),
        );
        $tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'cloudflare';
        if ( 'proximamente' === $tab ) {
            $tab = 'info';
        }
        if ( ! isset( $tabs[ $tab ] ) ) {
            $tab = 'cloudflare';
        }

        $base    = admin_url( 'options-general.php?page=' . self::SLUG );
        $options = $this->options();
        $log     = ( 'info' === $tab ) ? $this->recent_logs() : array( 'rows' => array(), 'total' => 0 );
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
                    <?php submit_button(); ?>
                </form>
            <?php elseif ( 'configuracion' === $tab ) : ?>
                <p class="description">
                    <?php esc_html_e( 'Con el interruptor apagado, esa pantalla no muestra Turnstile ni lo exige al enviar.', 'sjb-turnstile' ); ?>
                </p>
                <form method="post" action="#" class="sjb-turnstile-form" data-task="save_configuracion">
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
                    <?php submit_button(); ?>
                </form>
            <?php elseif ( 'info' === $tab ) : ?>
                <p class="description">
                    <?php esc_html_e( 'Con el registro apagado no se guarda ningún intento.', 'sjb-turnstile' ); ?>
                </p>
                <form method="post" action="#" class="sjb-turnstile-form" data-task="save_info">
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
                        <button type="button" class="button" id="sjb-turnstile-export"><?php esc_html_e( 'Exportar', 'sjb-turnstile' ); ?></button>
                        <button type="button" class="button" id="sjb-turnstile-empty"><?php esc_html_e( 'Vaciar registros', 'sjb-turnstile' ); ?></button>
                    </p>
                    <?php submit_button( __( 'Guardar cambios', 'sjb-turnstile' ) ); ?>
                </form>
                <div class="sjb-turnstile-log">
                    <p id="sjb-turnstile-log-empty"<?php echo $log['total'] > 0 ? ' hidden' : ''; ?>>
                        <?php esc_html_e( 'Todavía no hay intentos.', 'sjb-turnstile' ); ?>
                    </p>
                    <p id="sjb-turnstile-log-count" class="description"<?php echo $log['total'] > self::LOG_LIMIT ? '' : ' hidden'; ?>>
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: 1: filas visibles, 2: total de intentos. */
                                __( 'Mostrando los %1$d últimos de %2$d.', 'sjb-turnstile' ),
                                self::LOG_LIMIT,
                                (int) $log['total']
                            )
                        );
                        ?>
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
                            <?php foreach ( $log['rows'] as $row ) : ?>
                                <tr>
                                    <td><?php echo esc_html( mysql2date( 'd/m/Y H:i', (string) $row['created_at'] ) ); ?></td>
                                    <td><?php echo esc_html( (string) $row['ip'] ); ?></td>
                                    <td>
                                        <?php
                                        echo esc_html(
                                            'ok' === $row['result']
                                                ? __( 'Aceptado', 'sjb-turnstile' )
                                                : __( 'Rechazado', 'sjb-turnstile' )
                                        );
                                        ?>
                                    </td>
                                    <td><?php echo esc_html( (string) $row['page'] ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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
