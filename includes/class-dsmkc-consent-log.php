<?php
/**
 * DSMK Legal — Registro de consentimientos (prueba ante la AEPD).
 *
 * @package   DSMK_Legal
 * @file      includes/class-dsmkc-consent-log.php
 * @since     1.0.0
 * @author    DSMK Agencia Digital <hola@dsmk.es>
 * @license   GPL-2.0-or-later
 *
 * Responsabilidad:
 *   - Tabla {prefix}dsmkc_consents con una fila por cada decisión del usuario.
 *   - Endpoint REST público POST /wp-json/dsmkc/v1/consent que recibe la
 *     decisión desde el navegador.
 *   - Purga diaria de registros con más antigüedad que la configurada.
 *   - Exportación CSV y estadísticas para el panel y el informe.
 *
 * Minimización de datos (RGPD art. 5.1.c):
 *   - ID de consentimiento aleatorio (UUID) generado en el navegador.
 *   - IP anonimizada (último octeto IPv4 a 0; IPv6 truncada a /48).
 *   - Navegador: solo hash SHA-256 con sal del sitio (no reversible).
 *   - URL: solo la ruta, sin parámetros.
 *
 * Seguridad:
 *   - El endpoint es público por diseño (páginas en caché sin nonce). Se
 *     protege con validación estricta de cada campo, límite de tamaño y
 *     limitación de frecuencia por IP anonimizada (30 peticiones / 10 min).
 *   - Consultas con $wpdb->prepare() / $wpdb->insert().
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registro de consentimientos.
 *
 * @since 1.0.0
 */
final class DSMKC_Consent_Log {

	/** @var string[] Acciones válidas. */
	const ACTIONS = array( 'accept', 'reject', 'custom', 'service', 'gpc' );

	/**
	 * Nombre completo de la tabla.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dsmkc_consents';
	}

	/**
	 * Crea o actualiza la tabla (dbDelta).
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				consent_id varchar(36) NOT NULL,
				created_at datetime NOT NULL,
				action varchar(12) NOT NULL,
				categories varchar(100) NOT NULL DEFAULT '',
				services varchar(255) NOT NULL DEFAULT '',
				policy_version int(10) unsigned NOT NULL DEFAULT 1,
				banner_hash varchar(12) NOT NULL DEFAULT '',
				ip_anon varchar(45) NOT NULL DEFAULT '',
				ua_hash varchar(64) NOT NULL DEFAULT '',
				url varchar(255) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY consent_id (consent_id),
				KEY created_at (created_at)
			) {$charset};"
		);
		update_option( 'dsmkc_db_version', DSMKC_DB_VERSION, false );
	}

	/**
	 * Registra hooks.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
		add_action( 'dsmkc_daily', array( __CLASS__, 'purge' ) );
	}

	/**
	 * Registra el endpoint REST.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register_route() {
		register_rest_route(
			'dsmkc/v1',
			'/consent',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				// Público: el visitante anónimo debe poder registrar su decisión. Ver cabecera.
				'permission_callback' => '__return_true',
				'args'                => array(
					'id'     => array( 'type' => 'string', 'required' => true ),
					'action' => array( 'type' => 'string', 'required' => true, 'enum' => self::ACTIONS ),
					'c'      => array( 'type' => 'object', 'required' => false ),
					's'      => array( 'type' => 'array', 'required' => false ),
					'v'      => array( 'type' => 'integer', 'required' => false ),
					'bh'     => array( 'type' => 'string', 'required' => false ),
					'url'    => array( 'type' => 'string', 'required' => false ),
				),
			)
		);
	}

	/**
	 * Procesa una petición de registro.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( $request ) {
		if ( ! DSMKC_Settings::get( 'log_activo' ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'logged' => false ), 200 );
		}

		$id = (string) $request->get_param( 'id' );
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id ) ) {
			return new WP_Error( 'dsmkc_invalid', 'Invalid id', array( 'status' => 400 ) );
		}

		$ip_anon = self::anonymize_ip( self::client_ip() );
		if ( ! self::rate_ok( $ip_anon ) ) {
			return new WP_Error( 'dsmkc_rate', 'Too many requests', array( 'status' => 429 ) );
		}

		$categories = array();
		$c          = (array) $request->get_param( 'c' );
		foreach ( array( 'preferences', 'analytics', 'marketing' ) as $cat ) {
			if ( isset( $c[ $cat ] ) ) {
				$categories[] = $cat . ':' . ( empty( $c[ $cat ] ) ? 0 : 1 );
			}
		}
		$services = array();
		foreach ( (array) $request->get_param( 's' ) as $svc ) {
			$svc = sanitize_key( (string) $svc );
			if ( '' !== $svc && count( $services ) < 15 ) {
				$services[] = $svc;
			}
		}

		$path = (string) wp_parse_url( (string) $request->get_param( 'url' ), PHP_URL_PATH );
		$ua   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		self::insert(
			array(
				'consent_id'     => strtolower( $id ),
				'action'         => (string) $request->get_param( 'action' ),
				'categories'     => implode( ',', $categories ),
				'services'       => implode( ',', $services ),
				'policy_version' => absint( $request->get_param( 'v' ) ),
				'banner_hash'    => substr( preg_replace( '/[^a-f0-9]/', '', (string) $request->get_param( 'bh' ) ), 0, 12 ),
				'ip_anon'        => $ip_anon,
				'ua_hash'        => '' !== $ua ? hash_hmac( 'sha256', $ua, wp_salt( 'auth' ) ) : '',
				'url'            => substr( sanitize_text_field( $path ), 0, 255 ),
			)
		);

		return new WP_REST_Response( array( 'ok' => true, 'logged' => true ), 201 );
	}

	/**
	 * Inserta una fila.
	 *
	 * @since  1.0.0
	 * @param  array $row Datos ya saneados.
	 * @return bool
	 */
	public static function insert( array $row ) {
		global $wpdb;
		$row['created_at'] = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Tabla propia.
		return (bool) $wpdb->insert( self::table(), $row, array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ) );
	}

	/**
	 * IP del cliente (REMOTE_ADDR; no se confía en cabeceras manipulables).
	 *
	 * @since  1.0.0
	 * @return string
	 */
	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Anonimiza una IP.
	 *
	 * @since  1.0.0
	 * @param  string $ip IP.
	 * @return string
	 */
	public static function anonymize_ip( $ip ) {
		if ( function_exists( 'wp_privacy_anonymize_ip' ) ) {
			return (string) wp_privacy_anonymize_ip( $ip );
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return preg_replace( '/\.\d+$/', '.0', $ip );
		}
		return '';
	}

	/**
	 * Limitación de frecuencia por IP anonimizada.
	 *
	 * @since  1.0.0
	 * @param  string $ip_anon IP anonimizada.
	 * @return bool true si se permite.
	 */
	private static function rate_ok( $ip_anon ) {
		$key   = 'dsmkc_rl_' . md5( $ip_anon );
		$count = (int) get_transient( $key );
		if ( $count >= 30 ) {
			return false;
		}
		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Borra registros más antiguos que el plazo configurado.
	 *
	 * @since  1.0.0
	 * @return int Filas borradas.
	 */
	public static function purge() {
		global $wpdb;
		$months = (int) DSMKC_Settings::get( 'log_meses', 36 );
		$limit  = gmdate( 'Y-m-d H:i:s', strtotime( '-' . max( 12, $months ) . ' months' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia.
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created_at < %s', $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nombre de tabla interno.
	}

	/**
	 * Lista paginada de registros.
	 *
	 * @since  1.0.0
	 * @param  int    $page   Página (1..n).
	 * @param  int    $per    Filas por página.
	 * @param  string $search ID de consentimiento (parcial) o vacío.
	 * @return array{rows:array, total:int}
	 */
	public static function query( $page = 1, $per = 50, $search = '' ) {
		global $wpdb;
		$table  = self::table();
		$where  = '1=1';
		$params = array();
		if ( '' !== $search ) {
			$where   .= ' AND consent_id LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		$offset = max( 0, ( $page - 1 ) * $per );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Tabla propia; parámetros preparados.
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) );
		$sql   = "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $per, $offset ) ) ), ARRAY_A );
		// phpcs:enable
		return array( 'rows' => (array) $rows, 'total' => $total );
	}

	/**
	 * Estadísticas de los últimos N días.
	 *
	 * @since  1.0.0
	 * @param  int $days Días.
	 * @return array<string, int> acción => número, más 'total'.
	 */
	public static function stats( $days = 30 ) {
		global $wpdb;
		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabla propia.
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT action, COUNT(*) AS n FROM {$table} WHERE created_at >= %s GROUP BY action", $since ), ARRAY_A );
		$out   = array_fill_keys( self::ACTIONS, 0 );
		$total = 0;
		foreach ( (array) $rows as $row ) {
			$out[ $row['action'] ] = (int) $row['n'];
			$total                += (int) $row['n'];
		}
		$out['total'] = $total;
		return $out;
	}

	/**
	 * Consentimientos por mes (últimos N meses).
	 *
	 * @since  2.1.0
	 * @param  int $months Meses.
	 * @return array<string, array<string,int>> 'AAAA-MM' => [ accion => n, total => n ].
	 */
	public static function monthly( $months = 12 ) {
		global $wpdb;
		$table = self::table();
		$since = gmdate( 'Y-m-01 00:00:00', strtotime( '-' . ( max( 1, (int) $months ) - 1 ) . ' months' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabla propia.
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT DATE_FORMAT(created_at, '%%Y-%%m') AS ym, action, COUNT(*) AS n FROM {$table} WHERE created_at >= %s GROUP BY ym, action ORDER BY ym", $since ), ARRAY_A );
		$out   = array();
		foreach ( (array) $rows as $row ) {
			if ( ! isset( $out[ $row['ym'] ] ) ) {
				$out[ $row['ym'] ] = array_fill_keys( array_merge( self::ACTIONS, array( 'total' ) ), 0 );
			}
			$out[ $row['ym'] ][ $row['action'] ] = (int) $row['n'];
			$out[ $row['ym'] ]['total']        += (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Envía un CSV con todos los registros (o los filtrados) y termina.
	 *
	 * Seguridad: la llamada debe venir ya autorizada (capacidad + nonce) desde
	 * DSMKC_Admin. Las celdas que empiezan por = + - @ se neutralizan para
	 * evitar inyección de fórmulas en hojas de cálculo.
	 *
	 * @since  1.0.0
	 * @param  string $search Filtro.
	 * @return void
	 */
	public static function export_csv( $search = '' ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=consentimientos-' . sanitize_file_name( DSMKC_Legal::domain() ) . '-' . gmdate( 'Ymd' ) . '.csv' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- BOM para Excel.
		fputcsv( $out, array( 'id', 'consent_id', 'fecha_utc', 'accion', 'categorias', 'servicios', 'version_politica', 'version_banner', 'ip_anonimizada', 'navegador_hash', 'url' ), ';' );
		$page = 1;
		do {
			$batch = self::query( $page, 1000, $search );
			foreach ( $batch['rows'] as $row ) {
				fputcsv(
					$out,
					array_map(
						static function ( $cell ) {
							$cell = (string) $cell;
							return preg_match( '/^[=+\-@]/', $cell ) ? "'" . $cell : $cell;
						},
						array( $row['id'], $row['consent_id'], $row['created_at'], $row['action'], $row['categories'], $row['services'], $row['policy_version'], $row['banner_hash'], $row['ip_anon'], $row['ua_hash'], $row['url'] )
					),
					';'
				);
			}
			++$page;
		} while ( count( $batch['rows'] ) === 1000 );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
