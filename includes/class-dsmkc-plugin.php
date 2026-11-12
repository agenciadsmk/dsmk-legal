<?php
/**
 * DSMK Legal — Núcleo: carga de módulos, activación y tareas comunes.
 *
 * @package   DSMK_Legal
 * @file      includes/class-dsmkc-plugin.php
 * @since     1.0.0
 * @author    DSMK Agencia Digital <hola@dsmk.es>
 * @license   GPL-2.0-or-later
 *
 * Responsabilidad:
 *   - Instanciar y enganchar los módulos (bloqueador, front-end, legal,
 *     registro, escáner, admin).
 *   - Activación / desactivación: tabla, tareas programadas, token.
 *   - Actualizaciones de esquema de base de datos.
 *   - Sincronizar servicios: si aparece un servicio opcional nuevo, se
 *     incrementa la versión de la política (se vuelve a pedir consentimiento).
 *   - Vaciar las cachés de página de los plugins más comunes cuando cambia
 *     algo que afecta al HTML (evita servir páginas sin bloqueo).
 */

defined( 'ABSPATH' ) || exit;

/**
 * Clase principal (singleton).
 *
 * @since 1.0.0
 */
final class DSMKC_Plugin {

	/** @var DSMKC_Plugin|null Instancia única. */
	private static $instance = null;

	/** @var DSMKC_Blocker Bloqueador. */
	public $blocker;

	/**
	 * Devuelve la instancia e inicializa los módulos.
	 *
	 * @since  1.0.0
	 * @return DSMKC_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Engancha todos los módulos.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function boot() {
		load_plugin_textdomain( 'dsmk-legal', false, dirname( plugin_basename( DSMKC_FILE ) ) . '/languages' );

		if ( get_option( 'dsmkc_db_version' ) !== DSMKC_DB_VERSION ) {
			DSMKC_Consent_Log::install();
		}
		self::upgrade();

		$this->blocker = new DSMKC_Blocker();
		$this->blocker->hooks();
		( new DSMKC_Frontend() )->hooks();
		( new DSMKC_Legal() )->hooks();
		( new DSMKC_Documents() )->hooks();
		( new DSMKC_Consent_Log() )->hooks();
		( new DSMKC_Scanner() )->hooks();
		DSMKC_Updater::init(); // Solo filtros; la consulta la dispara WordPress al comprobar actualizaciones.

		if ( is_admin() ) {
			( new DSMKC_Admin() )->hooks();
		}

		add_action( 'dsmkc_settings_saved', array( __CLASS__, 'on_settings_saved' ) );
		add_action( 'init', array( __CLASS__, 'ensure_cron' ), 20 ); // Se registra durante `init` (prioridad 0) y se ejecuta en esta misma pasada.
		add_filter( 'trp_skip_gettext_processing', array( __CLASS__, 'skip_machine_retranslation' ), 999, 4 );
	}

	/**
	 * Impide que TranslatePress «retraduzca» por máquina los textos legales en español.
	 *
	 * Los textos de origen del plugin ya están en español y no hay .mo es_ES.
	 * TranslatePress trata esas cadenas gettext como pendientes de traducir y,
	 * en la vista en español, las sustituye por una traducción automática del
	 * propio español. El resultado cambia el texto legal: mezcla «tú» y «usted»,
	 * duplica puntos y cambia infinitivos por imperativos. Un texto legal no
	 * puede alterarse sin revisión, así que en cualquier idioma español se
	 * sirve el original. Los demás idiomas siguen el criterio del propio
	 * TranslatePress.
	 *
	 * @since  2.4.1
	 * @param  bool        $skip        Decisión previa.
	 * @param  string|null $translation Traducción propuesta (no se usa).
	 * @param  string|null $text        Texto original (no se usa).
	 * @param  string|null $domain      Dominio de texto.
	 * @return bool
	 */
	public static function skip_machine_retranslation( $skip, $translation = null, $text = null, $domain = null ) {
		if ( 'dsmk-legal' !== $domain ) {
			return $skip;
		}
		$lang = ( isset( $GLOBALS['TRP_LANGUAGE'] ) && is_string( $GLOBALS['TRP_LANGUAGE'] ) ) ? $GLOBALS['TRP_LANGUAGE'] : ( function_exists( 'determine_locale' ) ? determine_locale() : '' );
		return 0 === strpos( (string) $lang, 'es' ) ? true : $skip;
	}

	/**
	 * Migraciones entre versiones (se ejecuta en cada carga; barata si no hay cambios).
	 *
	 * 2.0.1: la creación automática de páginas pasa a ser opcional y desactivada.
	 * Se desactiva también en las webs que ya tenían la 2.0.0 instalada.
	 * 2.2.0: se borra el token de escaneo permanente (ahora es efímero).
	 * 2.2.1: importa los ajustes del plugin «DSMK Cookies» 1.0.0 de Arturo.
	 *
	 * @since  2.0.1
	 * @return void
	 */
	public static function upgrade() {
		$installed = (string) get_option( 'dsmkc_plugin_version', '' );
		if ( DSMKC_VERSION === $installed ) {
			return;
		}
		if ( version_compare( '' !== $installed ? $installed : '0', '2.0.1', '<' ) ) {
			$settings = get_option( DSMKC_Settings::OPTION );
			if ( is_array( $settings ) ) {
				$settings['paginas_auto'] = 0;
				update_option( DSMKC_Settings::OPTION, $settings, true );
				DSMKC_Settings::flush();
			}
		}
		self::migrate_dsmk_cookies_settings();
		if ( version_compare( '' !== $installed ? $installed : '0', '2.2.0', '<' ) ) {
			// 2.2.0: el token de escaneo permanente se sustituye por uno efímero.
			$data = get_option( DSMKC_Settings::DATA );
			if ( is_array( $data ) && isset( $data['scan_token'] ) ) {
				unset( $data['scan_token'] );
				update_option( DSMKC_Settings::DATA, $data, false );
				DSMKC_Settings::flush();
			}
		}
		update_option( 'dsmkc_plugin_version', DSMKC_VERSION, false );
	}

	/**
	 * Importa los ajustes del plugin «DSMK Cookies» 1.0.0 (Arturo), que comparte
	 * la opción `dsmkc_settings` con otra estructura (claves anidadas).
	 *
	 * Copia los datos del titular a los campos de DSMK Legal que estén vacíos y
	 * quita las claves anidadas, que DSMK Legal no usa y ensuciarían la
	 * exportación JSON. Se ejecuta en cada actualización y no hace nada si no
	 * hay datos de ese plugin.
	 *
	 * @since  2.2.1
	 * @return void
	 */
	private static function migrate_dsmk_cookies_settings() {
		$saved = get_option( DSMKC_Settings::OPTION );
		$keys  = array( 'company', 'pages', 'banner', 'consent', 'services', 'scripts', 'gcm', 'advanced' );
		if ( ! is_array( $saved ) || ! isset( $saved['company'] ) || ! is_array( $saved['company'] ) ) {
			return;
		}
		$map     = array(
			'owner'   => 'titular_nombre',
			'nif'     => 'titular_nif',
			'address' => 'titular_domicilio',
			'email'   => 'titular_email',
		);
		$company = $saved['company'];
		foreach ( $map as $from => $to ) {
			if ( empty( $saved[ $to ] ) && isset( $company[ $from ] ) && is_scalar( $company[ $from ] ) && '' !== trim( (string) $company[ $from ] ) ) {
				$saved[ $to ] = sanitize_text_field( (string) $company[ $from ] );
			}
		}
		foreach ( $keys as $key ) {
			unset( $saved[ $key ] );
		}
		update_option( DSMKC_Settings::OPTION, $saved, true );
		DSMKC_Settings::flush();
	}

	/**
	 * Activación del plugin.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function activate() {
		DSMKC_Consent_Log::install();
		if ( ! get_option( DSMKC_Settings::OPTION ) ) {
			add_option( DSMKC_Settings::OPTION, DSMKC_Settings::defaults(), '', true );
		}
		self::ensure_cron();
		self::sync_services();
		DSMKC_Legal::record_banner_version(); // Prueba de la versión del banner desde el primer consentimiento.
		// Primer escaneo en segundo plano (no bloquea la activación).
		wp_schedule_single_event( time() + 60, 'dsmkc_weekly_scan' );
		self::purge_caches();
	}

	/**
	 * Desactivación: elimina tareas programadas (los datos se conservan).
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'dsmkc_daily' );
		wp_clear_scheduled_hook( 'dsmkc_weekly_scan' );
		self::purge_caches();
	}

	/**
	 * Programa las tareas periódicas si faltan.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function ensure_cron() {
		if ( ! wp_next_scheduled( 'dsmkc_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'dsmkc_daily' );
		}
		if ( ! wp_next_scheduled( 'dsmkc_weekly_scan' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', 'dsmkc_weekly_scan' );
		}
	}

	/**
	 * Tras guardar ajustes: sincroniza servicios, histórico del banner y cachés.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function on_settings_saved() {
		DSMKC_Services::reset();
		self::sync_services();
		DSMKC_Legal::record_banner_version();
		self::purge_caches();
	}

	/**
	 * Compara los servicios opcionales activos con los anteriores. Si hay
	 * alguno nuevo (y está activada la opción), se vuelve a pedir el
	 * consentimiento incrementando la versión de la política.
	 *
	 * @since  1.0.0
	 * @return bool true si se incrementó la versión.
	 */
	public static function sync_services() {
		$ids = array();
		foreach ( DSMKC_Services::active() as $id => $service ) {
			if ( 'necessary' !== $service['category'] ) {
				$ids[] = $id . ':' . $service['category'];
			}
		}
		sort( $ids );
		$data   = DSMKC_Settings::data();
		$prev   = isset( $data['optional_ids'] ) ? (array) $data['optional_ids'] : null;
		$bumped = false;

		if ( null !== $prev && array_diff( $ids, $prev ) && DSMKC_Settings::get( 'bump_nuevos' ) ) {
			DSMKC_Settings::bump_version();
			$bumped = true;
		}
		if ( null === $prev || $ids !== $prev ) {
			DSMKC_Settings::update_data( array( 'optional_ids' => $ids, 'updated' => time() ) );
		}
		return $bumped;
	}

	/**
	 * Vacía las cachés de página de los plugins y hostings más comunes.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function purge_caches() {
		// Sin wp_cache_flush() (2.2.0): vaciaba toda la caché de objetos (Redis/
		// Memcached compartidos, todas las webs de un multisite). update_option()
		// ya invalida nuestras opciones; aquí solo se vacían cachés de página.
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hooks de terceros.
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain(); // WP Rocket.
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all(); // W3 Total Cache.
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache(); // WP Super Cache.
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache(); // SiteGround.
		}
		if ( class_exists( 'WpFastestCache' ) && function_exists( 'wpfc_clear_all_cache' ) ) {
			wpfc_clear_all_cache( true ); // WP Fastest Cache.
		}
		if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
			autoptimizeCache::clearall(); // Autoptimize.
		}
		do_action( 'litespeed_purge_all' );            // LiteSpeed Cache.
		do_action( 'breeze_clear_all_cache' );         // Breeze.
		do_action( 'cachify_flush_cache' );            // Cachify.
		do_action( 'swcfpc_purge_cache' );             // WP Cloudflare Super Page Cache.
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache(); // CSS de Elementor.
		}
		// phpcs:enable
		/**
		 * Permite vaciar otras cachés.
		 *
		 * @since 1.0.0
		 */
		do_action( 'dsmkc_purge_caches' );
	}
}
