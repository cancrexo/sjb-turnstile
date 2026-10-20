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

register_uninstall_hook( __FILE__, array( 'SJB_Turnstile', 'uninstall' ) );
add_action( 'plugins_loaded', array( 'SJB_Turnstile', 'init' ) );

/**
 * Cloudflare Turnstile en los formularios públicos de WordPress.
 */
class SJB_Turnstile {

    /** @var string */
    public const SLUG = 'sjb-turnstile';

    /** @var string */
    private const OPTION = 'sjb_turnstile';

    /** @var string */
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

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
     * Borra las claves al desinstalar el plugin.
     */
    public static function uninstall(): void {
        delete_option( self::OPTION );
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

        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
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
     * Claves guardadas. Cadenas vacías si aún no hay ajustes.
     *
     * @return array{site_key:string,secret_key:string}
     */
    private function options(): array {
        $stored = get_option( self::OPTION, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }

        return array(
            'site_key'   => isset( $stored['site_key'] ) ? (string) $stored['site_key'] : '',
            'secret_key' => isset( $stored['secret_key'] ) ? (string) $stored['secret_key'] : '',
        );
    }

    /**
     * Turnstile solo actúa cuando las dos claves están guardadas.
     */
    private function is_configured(): bool {
        $options = $this->options();

        return '' !== $options['site_key'] && '' !== $options['secret_key'];
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

        if ( is_user_logged_in() ) {
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

        $ip = isset( $_SERVER['REMOTE_ADDR'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
            : '';

        $response = wp_remote_post(
            self::VERIFY_URL,
            array(
                'timeout' => 8,
                'body'    => array(
                    'secret'   => $this->options()['secret_key'],
                    'response' => $token,
                    'remoteip' => $ip,
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

        if ( ! $this->is_configured() || ! isset( $_POST['log'] ) || $this->token_is_valid() ) {
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

        if ( $this->is_configured() && ! $this->token_is_valid() ) {
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

        if ( $this->is_configured() && ! $this->token_is_valid() ) {
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
        if ( ! $this->is_configured() || is_user_logged_in() ) {
            return $commentdata;
        }

        $type = isset( $commentdata['comment_type'] ) ? (string) $commentdata['comment_type'] : '';
        if ( in_array( $type, array( 'pingback', 'trackback' ), true ) ) {
            return $commentdata;
        }

        if ( $this->token_is_valid() ) {
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
     * Grupo de opciones de la pestaña Cloudflare.
     */
    public function register_settings(): void {
        register_setting(
            self::SLUG,
            self::OPTION,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( $this, 'sanitize_options' ),
                'default'           => array(
                    'site_key'   => '',
                    'secret_key' => '',
                ),
            )
        );
    }

    /**
     * Limpia las dos claves. Si el envío no es un array, conserva lo guardado.
     *
     * @param mixed $input Valores del formulario.
     * @return array{site_key:string,secret_key:string}
     */
    public function sanitize_options( $input ): array {
        if ( ! is_array( $input ) ) {
            return $this->options();
        }

        return array(
            'site_key'   => isset( $input['site_key'] ) ? sanitize_text_field( $input['site_key'] ) : '',
            'secret_key' => isset( $input['secret_key'] ) ? sanitize_text_field( $input['secret_key'] ) : '',
        );
    }

    /**
     * Pantalla de ajustes: pestaña Cloudflare y pestaña Próximamente.
     */
    public function render_admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'cloudflare';
        if ( ! in_array( $tab, array( 'cloudflare', 'proximamente' ), true ) ) {
            $tab = 'cloudflare';
        }

        $base     = admin_url( 'options-general.php?page=' . self::SLUG );
        $options  = $this->options();
        $is_cloud = ( 'cloudflare' === $tab );
        ?>
        <div class="wrap">
            <h1>SJB Turnstile</h1>

            <nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Secciones de SJB Turnstile', 'sjb-turnstile' ); ?>">
                <a href="<?php echo esc_url( $base . '&tab=cloudflare' ); ?>" class="nav-tab<?php echo $is_cloud ? ' nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Cloudflare', 'sjb-turnstile' ); ?>
                </a>
                <a href="<?php echo esc_url( $base . '&tab=proximamente' ); ?>" class="nav-tab<?php echo $is_cloud ? '' : ' nav-tab-active'; ?>">
                    <?php esc_html_e( 'Próximamente', 'sjb-turnstile' ); ?>
                </a>
            </nav>

            <?php if ( $is_cloud ) : ?>
                <?php if ( ! $this->is_configured() ) : ?>
                    <div class="notice notice-warning inline" style="margin:1em 0;">
                        <p><?php esc_html_e( 'Turnstile no se aplicará hasta que guardes la site key y la secret key.', 'sjb-turnstile' ); ?></p>
                    </div>
                <?php endif; ?>

                <form method="post" action="options.php">
                    <?php settings_fields( self::SLUG ); ?>
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
                                    name="<?php echo esc_attr( self::OPTION ); ?>[site_key]"
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
                                    name="<?php echo esc_attr( self::OPTION ); ?>[secret_key]"
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
            <?php else : ?>
                <p><?php esc_html_e( 'Próximamente.', 'sjb-turnstile' ); ?></p>
            <?php endif; ?>

            <p class="description">
                Daniel &quot;Cancrexo&quot; Prol · SJB Dixital ·
                <a href="mailto:cancrexo@gmail.com">cancrexo@gmail.com</a>
            </p>
        </div>
        <?php
    }
}
