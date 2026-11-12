<?php
/**
 * DSMK Legal — Arranque de pruebas sin WordPress.
 *
 * @package   DSMK_Legal
 * @file      tests/php/bootstrap.php
 * @since     1.0.0
 *
 * Define versiones mínimas de las funciones de WordPress que usan las clases
 * del plugin, con opciones en memoria. Permite probar la lógica real (bloqueo,
 * textos legales, saneado) sin una instalación de WordPress.
 * No se distribuye con el plugin (excluir tests/ del ZIP de producción).
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DSMKC_VERSION', '2.4.1' );
define( 'DSMKC_DB_VERSION', '2' );
define( 'DSMKC_DIR', dirname( __DIR__, 2 ) . '/' );
define( 'DSMKC_FILE', DSMKC_DIR . 'dsmk-legal.php' );
define( 'DSMKC_URL', '/plugin/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'ENT_QUOTES_WP', ENT_QUOTES );

$GLOBALS['__opts']    = array();
$GLOBALS['__home']    = getenv( 'DSMKC_HOME' ) ? getenv( 'DSMKC_HOME' ) : 'http://localhost:8765';
$GLOBALS['__pages']   = array();

function __( $t, $d = null ) { return $t; }
function _e( $t, $d = null ) { echo $t; }
function _n( $s, $p, $n, $d = null ) { return 1 === (int) $n ? $s : $p; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $t, $d = null ) { return esc_html( $t ); }
function esc_attr__( $t, $d = null ) { return esc_attr( $t ); }
function esc_html_e( $t, $d = null ) { echo esc_html( $t ); }
function esc_attr_e( $t, $d = null ) { echo esc_attr( $t ); }
function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES, 'UTF-8' ); }
function esc_url_raw( $u ) { return (string) $u; }
function wp_make_link_relative( $u ) { return preg_replace( '|^(https?:)?//[^/]+(/?.*)|i', '$2', $u ); }
function esc_js( $t ) { return addslashes( (string) $t ); }
function wp_kses_post( $t ) { return preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', (string) $t ); } // Como el real: sin <script> ni <style>.
function wp_strip_all_tags( $t, $remove_breaks = false ) { $t = trim( strip_tags( (string) $t ) ); return $remove_breaks ? preg_replace( '/[\r\n\t ]+/', ' ', $t ) : $t; }
function wpautop( $t ) { return '<p>' . str_replace( "\n\n", '</p><p>', trim( (string) $t ) ) . '</p>'; }
function wp_unslash( $v ) { return $v; }
function sanitize_text_field( $t ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $t ) ) ); }
function sanitize_textarea_field( $t ) { return trim( strip_tags( (string) $t ) ); }
function sanitize_email( $e ) { return filter_var( $e, FILTER_VALIDATE_EMAIL ) ? $e : ''; }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_html_class( $c ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $c ); }
function sanitize_hex_color( $c ) { return preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', (string) $c ) ? $c : null; }
function sanitize_file_name( $n ) { return preg_replace( '/[^a-z0-9.\-]/i', '', $n ); }
function absint( $n ) { return abs( (int) $n ); }
function wp_parse_args( $a, $d = array() ) { return array_merge( $d, (array) $a ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function wp_list_pluck( $list, $field ) { $o = array(); foreach ( $list as $k => $v ) { $o[ $k ] = is_array( $v ) ? $v[ $field ] : $v->$field; } return $o; }
$GLOBALS['__filters'] = array();
function add_filter( $tag, $cb ) { $GLOBALS['__filters'][ $tag ][] = $cb; return true; }
function apply_filters( $tag, $value ) { $args = array_slice( func_get_args(), 2 ); foreach ( isset( $GLOBALS['__filters'][ $tag ] ) ? $GLOBALS['__filters'][ $tag ] : array() as $cb ) { $value = call_user_func_array( $cb, array_merge( array( $value ), $args ) ); } return $value; }
function remove_all_filters( $tag ) { unset( $GLOBALS['__filters'][ $tag ] ); }
function do_action() {}
function add_action() {}
function add_shortcode() {}
function shortcode_atts( $pairs, $atts ) { return array_merge( $pairs, array_intersect_key( (array) $atts, $pairs ) ); }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function add_option( $k, $v ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function get_transient( $k ) { return get_option( '_t_' . $k ); }
function set_transient( $k, $v ) { return update_option( '_t_' . $k, $v ); }
function delete_transient( $k ) { unset( $GLOBALS['__opts'][ '_t_' . $k ] ); return true; }
function wp_generate_password( $l = 12 ) { return substr( bin2hex( random_bytes( $l ) ), 0, $l ); }
function home_url( $p = '' ) { return $GLOBALS['__home'] . ( $p ? $p : '' ); }
function rest_url( $p = '' ) { return $GLOBALS['__home'] . '/wp-json/' . ltrim( $p, '/' ); }
function get_bloginfo( $s ) { return 'Web de Prueba DSMK'; }
function get_permalink( $id ) { $id = is_object( $id ) ? $id->ID : $id; return isset( $GLOBALS['__pages'][ $id ] ) ? $GLOBALS['__pages'][ $id ]['url'] : ''; }
function get_post_status( $id ) { return isset( $GLOBALS['__pages'][ $id ] ) ? 'publish' : false; }
function get_post_type( $id ) { return isset( $GLOBALS['__pages'][ $id ] ) ? 'page' : false; }
function get_post_field( $f, $id ) { return isset( $GLOBALS['__pages'][ $id ] ) ? $GLOBALS['__pages'][ $id ]['content'] : ''; }
function wp_date( $f, $ts = null ) { return gmdate( 'd/m/Y', $ts ? $ts : time() ); }
function plugin_basename( $f ) { return isset( $GLOBALS['__basename'] ) ? $GLOBALS['__basename'] : 'dsmk-legal/dsmk-legal.php'; }
function is_admin() { return ! empty( $GLOBALS['__is_admin'] ); }
function wp_doing_ajax() { return false; }
function wp_doing_cron() { return false; }
function wp_is_json_request() { return false; }
function is_feed() { return false; }
function is_robots() { return false; }
function is_trackback() { return false; }
function is_customize_preview() { return false; }
$GLOBALS['__can']    = false; // Capacidades del usuario simulado.
$GLOBALS['__logged'] = false;
function current_user_can() { return ! empty( $GLOBALS['__can'] ); }
function is_user_logged_in() { return ! empty( $GLOBALS['__logged'] ); }
function get_current_user_id() { return $GLOBALS['__logged'] ? 1 : 0; }
function wp_create_nonce( $a ) { return substr( md5( 'nonce|' . $a ), 0, 10 ); }
function wp_verify_nonce( $n, $a ) { return $GLOBALS['__logged'] && hash_equals( wp_create_nonce( $a ), (string) $n ) ? 1 : false; }
function add_query_arg( $k, $v, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . rawurlencode( $k ) . '=' . rawurlencode( $v ); }
function selected() { return ''; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
class WP_Error { public $m; public $code; public $data; function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->m = $m; $this->data = $d; } function get_error_message() { return $this->m; } function get_error_code() { return $this->code; } }
class WP_REST_Response { public $data; public $status; function __construct( $d = null, $s = 200 ) { $this->data = $d; $this->status = $s; } }
/** Petición REST mínima. */
class DSMKC_Test_Request { private $p; function __construct( array $p ) { $this->p = $p; } function get_param( $k ) { return isset( $this->p[ $k ] ) ? $this->p[ $k ] : null; } }
/** $wpdb mínimo: guarda las filas insertadas; $fail simula un error de la base de datos. */
class DSMKC_Test_WPDB { public $prefix = 'wp_'; public $rows = array(); public $fail = false; public $columns = array(); public $queries = array(); function get_col( $q ) { return $this->columns; } function query( $q ) { $this->queries[] = $q; return 1; } function insert( $t, $row ) { if ( $this->fail || strlen( $row['services'] ) > 255 || strlen( $row['consent_id'] ) > 36 ) { return false; } $this->rows[] = $row; return 1; } }
$GLOBALS['wpdb'] = new DSMKC_Test_WPDB();
function wp_salt( $s = '' ) { return 'salt-' . $s; }
function current_time( $t, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function wp_privacy_anonymize_ip( $ip ) { return preg_replace( '/\.\d+$/', '.0', $ip ); }
function wp_insert_post( $a ) { $id = max( array_merge( array( 100 ), array_keys( $GLOBALS['__pages'] ) ) ) + 1; $GLOBALS['__pages'][ $id ] = array( 'url' => $GLOBALS['__home'] . '/p' . $id . '/', 'content' => $a['post_content'], 'title' => $a['post_title'] ); return $id; }
function get_posts( $a = array() ) { $o = array(); foreach ( $GLOBALS['__pages'] as $id => $p ) { $o[] = (object) array( 'ID' => $id, 'post_title' => isset( $p['title'] ) ? $p['title'] : '', 'post_name' => isset( $p['slug'] ) ? $p['slug'] : '', 'post_content' => $p['content'] ); } return $o; }
function get_pages( $a = array() ) { return get_posts(); }
function get_post_meta( $id, $k = '', $single = false ) { return isset( $GLOBALS['__pages'][ $id ]['meta'][ $k ] ) ? $GLOBALS['__pages'][ $id ]['meta'][ $k ] : ''; }
function post_type_exists() { return false; }
function get_post( $id ) { return isset( $GLOBALS['__pages'][ $id ] ) ? (object) array( 'ID' => $id, 'post_content' => $GLOBALS['__pages'][ $id ]['content'] ) : null; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['__pages'][ $id ]['meta'][ $k ] = $v; return true; }
function delete_post_meta( $id, $k ) { unset( $GLOBALS['__pages'][ $id ]['meta'][ $k ] ); return true; }
function wp_update_post( $a ) { $GLOBALS['__pages'][ $a['ID'] ]['content'] = $a['post_content']; return $a['ID']; }
function get_page_by_path() { return null; }
function wp_rand() { return mt_rand(); }
function nocache_headers() {}
function wp_get_current_user() { return new class { public $user_login = 'leonor'; function exists() { return true; } }; }
$GLOBALS['__http'] = null;
function wp_remote_get( $url, $args = array() ) { return call_user_func( $GLOBALS['__http'], $url, $args ); }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
// 2.3.0 (actualizador): HTTP seguro con descarga a archivo, transients de red y archivos temporales.
function wp_safe_remote_get( $url, $args = array() ) {
	$GLOBALS['__safe_http'][] = array( $url, $args );
	$r = call_user_func( $GLOBALS['__http'], $url, $args );
	if ( ! is_wp_error( $r ) && ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
		file_put_contents( $args['filename'], $r['body'] );
	}
	return $r;
}
function wp_http_validate_url( $url ) { return filter_var( $url, FILTER_VALIDATE_URL ) && ! preg_match( '#^https?://(localhost|127\.|10\.|192\.168\.)#', $url ) ? $url : false; }
function get_site_transient( $k ) { return get_transient( 'site_' . $k ); }
function set_site_transient( $k, $v, $e = 0 ) { return set_transient( 'site_' . $k, $v ); }
function delete_site_transient( $k ) { return delete_transient( 'site_' . $k ); }
$GLOBALS['__multisite'] = false;
function is_multisite() { return ! empty( $GLOBALS['__multisite'] ); }
function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); }
function wp_tempnam( $f = '' ) { return tempnam( sys_get_temp_dir(), 'dsmkc' ); }
function wp_delete_file( $f ) { if ( is_file( $f ) ) { unlink( $f ); } }
function wp_remote_retrieve_cookies( $r ) { return $r['cookies']; }
function wp_remote_retrieve_headers( $r ) { return $r['headers']; }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function delete_option( $k ) { unset( $GLOBALS['__opts'][ $k ] ); return true; }
function get_the_title( $id ) { return isset( $GLOBALS['__pages'][ $id ]['title'] ) ? $GLOBALS['__pages'][ $id ]['title'] : ''; }
function checked() { return ''; }

require DSMKC_DIR . 'includes/class-dsmkc-settings.php';
require DSMKC_DIR . 'includes/class-dsmkc-updater.php';
require DSMKC_DIR . 'includes/class-dsmkc-services.php';
require DSMKC_DIR . 'includes/class-dsmkc-blocker.php';
require DSMKC_DIR . 'includes/class-dsmkc-legal.php';
require DSMKC_DIR . 'includes/class-dsmkc-documents.php';
require DSMKC_DIR . 'includes/class-dsmkc-frontend.php';

/**
 * Configura una web de prueba: titular, páginas legales y servicios detectados.
 *
 * @param array $settings Ajustes extra.
 * @param array $data     Estado interno extra.
 */
function dsmkc_test_setup( array $settings = array(), array $data = array() ) {
	$GLOBALS['__filters'] = array();
	$GLOBALS['__pages'] = array(
		11 => array( 'url' => $GLOBALS['__home'] . '/politica-de-cookies/', 'content' => '[dsmk_politica_cookies]' ),
		12 => array( 'url' => $GLOBALS['__home'] . '/politica-de-privacidad/', 'content' => '[dsmk_politica_privacidad]' ),
		13 => array( 'url' => $GLOBALS['__home'] . '/aviso-legal/', 'content' => '[dsmk_aviso_legal]' ),
	);
	$GLOBALS['__opts']['dsmkc_settings'] = array_merge(
		DSMKC_Settings::defaults(),
		array(
			'titular_nombre'    => 'Taller Ejemplo SLU',
			'titular_nif'       => 'B00000000',
			'titular_domicilio' => 'C/ Ejemplo 1, 41003 Sevilla',
			'titular_email'     => 'hola@ejemplo.es',
			'pagina_cookies'    => 11,
			'pagina_privacidad' => 12,
			'pagina_aviso'      => 13,
		),
		$settings
	);
	$GLOBALS['__opts']['dsmkc_data'] = array_merge(
		array(
			'policy_version' => 3,
			'detected'       => array(
				'ga4'     => array( 'first' => time(), 'last' => time(), 'via' => 'test' ),
				'youtube' => array( 'first' => time(), 'last' => time(), 'via' => 'test' ),
			),
			'last_scan'      => time(),
		),
		$data
	);
	DSMKC_Settings::flush();
	DSMKC_Services::reset();
}
