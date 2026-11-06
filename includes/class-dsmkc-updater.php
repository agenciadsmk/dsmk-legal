<?php
/**
 * Actualizaciones desde el servidor de DSMK (sin wordpress.org).
 *
 * @package   DSMK_Legal
 * @file      includes/class-dsmkc-updater.php
 * @since     2.3.0
 * @author    DSMK Agencia Digital <hola@dsmk.es>
 *
 * Responsabilidad: que cada web con el plugin instalado vea el aviso normal de
 * WordPress «Hay una nueva versión disponible», pueda actualizar con un clic o
 * automáticamente, y vea el changelog en la ventana «Ver detalles».
 *
 * Funcionamiento:
 *   1. El flujo de publicación (.github/workflows/release.yml) deja en un
 *      servidor público un `info.json` y el ZIP de la versión:
 *        { "slug": "dsmk-legal", "version": "2.3.0",
 *          "download_url": "https://…/dsmk-legal-2.3.0.zip",
 *          "sha256": "<64 hex>", "signature": "<Ed25519 en base64>",
 *          "requires": "6.0", "requires_php": "7.4", "tested": "6.8",
 *          "last_updated": "…", "changelog_html": "…" }
 *   2. WordPress comprueba actualizaciones (cada 12 h, o al pulsar «Comprobar
 *      de nuevo»). Aquí se consulta el JSON (caché de 12 h, 3 h si falla), se
 *      verifica su firma y se añade la versión a la lista de WordPress.
 *   3. Al actualizar, este plugin descarga el ZIP él mismo y comprueba su
 *      SHA-256 (firmado) antes de entregárselo al instalador.
 *
 * Seguridad (decisiones que no se pueden revertir):
 *   - FIRMA Ed25519. El JSON firma «dsmk-legal\n<versión>\n<sha256>» con la
 *     clave privada de DSMK (secreto UPDATE_SIGNING_KEY de GitHub, nunca en el
 *     repositorio). La clave pública va en self::PUBLIC_KEY. Aunque alguien
 *     manipulara GitHub Pages o el servidor de descargas, ninguna web
 *     instalaría un ZIP que DSMK no haya firmado. Sin firma válida no hay
 *     actualización. Verificación con sodium (PHP 7.2+ o sodium_compat de
 *     WordPress).
 *   - SHA-256: el ZIP descargado debe coincidir con la huella firmada. Si no
 *     hay huella disponible para nuestro paquete, la instalación se cancela
 *     (falla cerrado, nunca instala sin verificar).
 *   - Solo URL https validadas; descargas con wp_safe_remote_get.
 *   - Cabecera «Update URI» en dsmk-legal.php: WordPress nunca lo sustituirá
 *     por un plugin de wordpress.org con el mismo nombre de carpeta, y la
 *     ventana de detalles no enlaza a wordpress.org (`external`).
 *   - El origen de las actualizaciones (URL) y la activación automática NO se
 *     importan ni se exportan (un JSON importado no puede redirigirlas).
 *   - Multisite: los ajustes de actualización de un subsitio se ignoran; manda
 *     la red (constantes o filtro). El panel no los muestra en multisite.
 *   - La clave por web (opcional) solo se envía al mismo host que el JSON.
 *
 * Origen del JSON, por prioridad: constante DSMK_LEGAL_UPDATE_URL
 * (wp-config.php) › ajuste «URL de actualizaciones» (solo sitio único) ›
 * filtro `dsmkc_update_url` › self::DEFAULT_URL.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Integración con el sistema de actualizaciones de WordPress.
 *
 * @since 2.3.0
 */
class DSMKC_Updater {

	/** @var string Slug del plugin (carpeta esperada). */
	const SLUG = 'dsmk-legal';

	/** @var string Transient de red con la respuesta del servidor. */
	const CACHE = 'dsmkc_update_info';

	/** @var string JSON publicado por .github/workflows/release.yml en GitHub Pages. */
	const DEFAULT_URL = 'https://agenciadsmk.github.io/dsmk-legal/info.json';

	/**
	 * Clave pública Ed25519 (base64) con la que se verifican las versiones.
	 * La escribe `php bin/make-signing-key.php` en el equipo de DSMK; su pareja
	 * privada es el secreto UPDATE_SIGNING_KEY del repositorio. Vacía = el
	 * plugin no acepta ninguna actualización (y la publicación se niega a
	 * firmar). Cambiarla exige publicar antes una versión firmada con la clave
	 * antigua que traiga la nueva (ver README › Actualizaciones › Rotar la clave).
	 *
	 * @var string
	 */
	const PUBLIC_KEY = '';

	/** @var int Caché de una respuesta correcta (segundos). */
	const TTL_OK = 43200;

	/** @var int Caché tras un error, para no consultar en cada carga (segundos). */
	const TTL_ERROR = 10800;

	/** @var bool Ya se forzó una consulta en esta petición («Comprobar de nuevo»). */
	private static $forced = false;

	/**
	 * Registra los filtros de WordPress.
	 *
	 * Ligero: solo engancha filtros; la petición HTTP se hace cuando WordPress
	 * comprueba actualizaciones, no en cada visita.
	 *
	 * @since  2.3.0
	 * @return void
	 */
	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 10, 4 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'source_selection' ), 10, 4 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto_update' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear' ), 10, 0 );
	}

	/**
	 * Ruta del plugin para WordPress (p. ej. dsmk-legal/dsmk-legal.php).
	 *
	 * @since  2.3.0
	 * @return string
	 */
	public static function basename() {
		return plugin_basename( DSMKC_FILE );
	}

	/**
	 * ¿Se pueden usar los ajustes de actualización de esta web?
	 *
	 * En multisite no: los ajustes son por subsitio pero las actualizaciones
	 * son de toda la red, y el administrador de un subsitio no debe poder
	 * decidir qué código se instala en la red.
	 *
	 * @since  2.3.0
	 * @return bool
	 */
	public static function settings_allowed() {
		return ! is_multisite();
	}

	/**
	 * URL del JSON de actualizaciones.
	 *
	 * @since  2.3.0
	 * @return string URL https o vacía si la configurada no es válida.
	 */
	public static function url() {
		if ( defined( 'DSMK_LEGAL_UPDATE_URL' ) && DSMK_LEGAL_UPDATE_URL ) {
			$url = (string) DSMK_LEGAL_UPDATE_URL;
		} else {
			$url = self::settings_allowed() ? trim( (string) DSMKC_Settings::get( 'update_url', '' ) ) : '';
			if ( '' === $url ) {
				/**
				 * URL del JSON de actualizaciones de DSMK Legal.
				 *
				 * @since 2.3.0
				 * @param string $url URL por defecto (GitHub Pages del repositorio).
				 */
				$url = (string) apply_filters( 'dsmkc_update_url', self::DEFAULT_URL );
			}
		}
		return self::is_https( $url ) ? $url : '';
	}

	/**
	 * Clave por web para el servidor de actualizaciones (opcional).
	 *
	 * @since  2.3.0
	 * @return string
	 */
	private static function key() {
		if ( defined( 'DSMK_LEGAL_UPDATE_KEY' ) && DSMK_LEGAL_UPDATE_KEY ) {
			return (string) DSMK_LEGAL_UPDATE_KEY;
		}
		return self::settings_allowed() ? trim( (string) DSMKC_Settings::get( 'update_key', '' ) ) : '';
	}

	/**
	 * Clave pública en uso (la constante DSMK_LEGAL_UPDATE_PUBKEY de
	 * wp-config.php la sustituye, p. ej. en staging o en una rotación de emergencia).
	 *
	 * @since  2.3.0
	 * @return string Clave en binario (32 bytes) o vacía.
	 */
	private static function public_key() {
		$b64 = defined( 'DSMK_LEGAL_UPDATE_PUBKEY' ) && DSMK_LEGAL_UPDATE_PUBKEY ? (string) DSMK_LEGAL_UPDATE_PUBKEY : self::PUBLIC_KEY;
		$raw = base64_decode( $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Clave pública.
		return is_string( $raw ) && 32 === strlen( $raw ) ? $raw : '';
	}

	/**
	 * Mensaje firmado de una versión (une slug, versión y huella del ZIP).
	 *
	 * @since  2.3.0
	 * @param  string $version Versión.
	 * @param  string $sha256  Huella del ZIP (hex).
	 * @return string
	 */
	public static function signed_message( $version, $sha256 ) {
		return self::SLUG . "\n" . $version . "\n" . strtolower( $sha256 );
	}

	/**
	 * ¿Es una URL https válida y pública?
	 *
	 * @since  2.3.0
	 * @param  string $url URL.
	 * @return bool
	 */
	private static function is_https( $url ) {
		return 0 === strpos( strtolower( (string) $url ), 'https://' ) && false !== wp_http_validate_url( $url );
	}

	/**
	 * Host en minúsculas de una URL.
	 *
	 * @since  2.3.0
	 * @param  string $url URL.
	 * @return string
	 */
	private static function host( $url ) {
		return strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	}

	/**
	 * Argumentos HTTP comunes.
	 *
	 * La clave (si hay) solo se envía al mismo host que el JSON y sin seguir
	 * redirecciones, para que no acabe en un tercero.
	 *
	 * @since  2.3.0
	 * @param  int    $timeout Segundos.
	 * @param  string $target  URL a la que se va a pedir.
	 * @return array
	 */
	private static function http_args( $timeout, $target ) {
		$args = array(
			'timeout'    => $timeout,
			'user-agent' => 'DSMK-Legal/' . DSMKC_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
			'headers'    => array( 'Accept' => 'application/json, application/zip' ),
		);
		$key  = self::key();
		$json = self::url();
		if ( '' !== $key && '' !== $json && self::host( $target ) === self::host( $json ) ) {
			$args['headers']['X-DSMK-Key']  = $key;
			$args['headers']['X-DSMK-Site'] = self::host( home_url() );
			$args['redirection']            = 0;
		}
		return $args;
	}

	/**
	 * ¿Ha pedido el administrador «Comprobar de nuevo» en Escritorio › Actualizaciones?
	 *
	 * @since  2.3.0
	 * @return bool
	 */
	private static function force_requested() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Mismo parámetro que usa WordPress; solo fuerza una lectura.
		return ! self::$forced && is_admin() && ! empty( $_GET['force-check'] ) && current_user_can( 'update_plugins' );
	}

	/**
	 * Datos de la última versión publicada (con caché).
	 *
	 * @since  2.3.0
	 * @param  bool $force Saltarse la caché.
	 * @return array|WP_Error Datos validados (firma incluida) o error.
	 */
	public static function remote( $force = false ) {
		if ( ! $force && self::force_requested() ) {
			$force        = true;
			self::$forced = true;
		}
		$cached = $force ? false : get_site_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return isset( $cached['error'] ) ? new WP_Error( 'dsmkc_update', $cached['error'] ) : $cached;
		}

		$url  = self::url();
		$data = '' === $url
			? new WP_Error( 'dsmkc_update', __( 'La URL de actualizaciones no es https o no es válida.', 'dsmk-legal' ) )
			: self::fetch( $url );

		if ( is_wp_error( $data ) ) {
			set_site_transient( self::CACHE, array( 'error' => $data->get_error_message(), 'checked' => time() ), self::TTL_ERROR );
			return $data;
		}
		$data['checked'] = time();
		set_site_transient( self::CACHE, $data, self::TTL_OK );
		return $data;
	}

	/**
	 * Descarga y valida el JSON.
	 *
	 * @since  2.3.0
	 * @param  string $url URL del JSON.
	 * @return array|WP_Error
	 */
	private static function fetch( $url ) {
		$response = wp_safe_remote_get( $url, self::http_args( 10, $url ) );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'dsmkc_update', $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			/* translators: %d: código HTTP. */
			return new WP_Error( 'dsmkc_update', sprintf( __( 'El servidor de actualizaciones respondió %d.', 'dsmk-legal' ), $code ) );
		}
		return self::validate( json_decode( (string) wp_remote_retrieve_body( $response ), true ) );
	}

	/**
	 * Valida, verifica la firma y normaliza el JSON de una versión.
	 *
	 * @since  2.3.0
	 * @param  mixed $data JSON decodificado.
	 * @return array|WP_Error
	 */
	public static function validate( $data ) {
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'dsmkc_update', __( 'El JSON de actualizaciones no es válido.', 'dsmk-legal' ) );
		}
		if ( isset( $data['slug'] ) && self::SLUG !== $data['slug'] ) {
			return new WP_Error( 'dsmkc_update', __( 'El JSON de actualizaciones es de otro plugin.', 'dsmk-legal' ) );
		}
		$version = isset( $data['version'] ) && is_string( $data['version'] ) ? $data['version'] : '';
		if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
			return new WP_Error( 'dsmkc_update', __( 'El JSON de actualizaciones no trae una versión válida.', 'dsmk-legal' ) );
		}
		$package = isset( $data['download_url'] ) && is_string( $data['download_url'] ) ? $data['download_url'] : '';
		if ( ! self::is_https( $package ) ) {
			return new WP_Error( 'dsmkc_update', __( 'El enlace de descarga no es https.', 'dsmk-legal' ) );
		}
		$sha = isset( $data['sha256'] ) && is_string( $data['sha256'] ) ? strtolower( $data['sha256'] ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $sha ) ) {
			return new WP_Error( 'dsmkc_update', __( 'El JSON de actualizaciones no trae la huella SHA-256 del ZIP.', 'dsmk-legal' ) );
		}
		if ( ! self::signature_ok( $version, $sha, isset( $data['signature'] ) ? $data['signature'] : '' ) ) {
			return new WP_Error( 'dsmkc_update', __( 'La firma de la versión publicada no es válida: no se ofrece la actualización.', 'dsmk-legal' ) );
		}
		$text = static function ( $key, $pattern = '/^[\d.]+$/' ) use ( $data ) {
			return isset( $data[ $key ] ) && is_string( $data[ $key ] ) && preg_match( $pattern, $data[ $key ] ) ? $data[ $key ] : '';
		};
		return array(
			'version'        => $version,
			'download_url'   => $package,
			'sha256'         => $sha,
			'requires'       => $text( 'requires' ),
			'requires_php'   => $text( 'requires_php' ),
			'tested'         => $text( 'tested' ),
			'last_updated'   => $text( 'last_updated', '/^[\dT:\- +Z]+$/' ),
			'changelog_html' => isset( $data['changelog_html'] ) && is_string( $data['changelog_html'] ) ? wp_kses_post( $data['changelog_html'] ) : '',
		);
	}

	/**
	 * Verifica la firma Ed25519 de una versión.
	 *
	 * @since  2.3.0
	 * @param  string $version   Versión.
	 * @param  string $sha256    Huella del ZIP.
	 * @param  mixed  $signature Firma en base64.
	 * @return bool
	 */
	private static function signature_ok( $version, $sha256, $signature ) {
		$key = self::public_key();
		$sig = is_string( $signature ) ? base64_decode( $signature, true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Firma.
		if ( '' === $key || ! is_string( $sig ) || 64 !== strlen( $sig ) || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return false;
		}
		try {
			return sodium_crypto_sign_verify_detached( $sig, self::signed_message( $version, $sha256 ), $key );
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Datos del plugin en el formato de la lista de actualizaciones de WordPress.
	 *
	 * Lleva la huella (`dsmkc_sha256`) junto al paquete: así la verificación
	 * de la descarga no depende de la caché del JSON.
	 *
	 * @since  2.3.0
	 * @param  array $r Datos validados.
	 * @return object
	 */
	private static function item( array $r ) {
		return (object) array(
			'id'           => self::DEFAULT_URL,
			'slug'         => self::SLUG,
			'plugin'       => self::basename(),
			'new_version'  => $r['version'],
			'url'          => 'https://dsmk.es',
			'package'      => $r['download_url'],
			'requires'     => $r['requires'],
			'requires_php' => $r['requires_php'],
			'tested'       => $r['tested'],
			'icons'        => array(),
			'banners'      => array(),
			'dsmkc_sha256' => $r['sha256'],
		);
	}

	/**
	 * Añade la versión nueva (o «sin novedades») a la comprobación de WordPress.
	 *
	 * Compara con la versión que WordPress ha leído del disco
	 * ($transient->checked), no con la constante en memoria: tras una
	 * actualización automática la misma petición vuelve a comprobar y la
	 * constante aún es la antigua (sin esto se reinstalaría la misma versión).
	 * Rellenar `no_update` hace que en Plugins aparezca el enlace
	 * «Activar las actualizaciones automáticas» aunque no haya versión nueva.
	 *
	 * @since  2.3.0
	 * @param  mixed $transient Valor de update_plugins.
	 * @return mixed
	 */
	public static function check( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$remote = self::remote();
		if ( is_wp_error( $remote ) ) {
			return $transient; // Sin datos: WordPress sigue como siempre.
		}
		$base      = self::basename();
		$installed = isset( $transient->checked[ $base ] ) ? (string) $transient->checked[ $base ] : DSMKC_VERSION;
		$item      = self::item( $remote );
		if ( version_compare( $remote['version'], $installed, '>' ) ) {
			$transient->response[ $base ] = $item;
			unset( $transient->no_update[ $base ] );
		} else {
			$item->new_version             = $installed;
			$item->package                 = '';
			$transient->no_update[ $base ] = $item;
			unset( $transient->response[ $base ] );
		}
		return $transient;
	}

	/**
	 * Ventana «Ver detalles de la versión» con el changelog.
	 *
	 * @since  2.3.0
	 * @param  false|object|array $result Resultado previo.
	 * @param  string             $action Acción de plugins_api.
	 * @param  object             $args   Argumentos (slug).
	 * @return false|object|array
	 */
	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$remote = self::remote();
		if ( is_wp_error( $remote ) ) {
			return $result;
		}
		return (object) array(
			'name'          => 'DSMK Legal',
			'slug'          => self::SLUG,
			'version'       => $remote['version'],
			'author'        => '<a href="https://dsmk.es">DSMK Agencia Digital</a>',
			'homepage'      => 'https://dsmk.es',
			'external'      => true, // Sin enlace a una ficha de wordpress.org que no existe.
			'requires'      => $remote['requires'],
			'requires_php'  => $remote['requires_php'],
			'tested'        => $remote['tested'],
			'last_updated'  => $remote['last_updated'],
			'download_link' => $remote['download_url'],
			'sections'      => array(
				'description' => '<p>' . esc_html__( 'Hub legal de DSMK: aviso legal, privacidad, cookies (banner, bloqueo previo y registro de consentimientos), términos, condiciones de venta y desistimiento.', 'dsmk-legal' ) . '</p>',
				'changelog'   => '' !== $remote['changelog_html'] ? $remote['changelog_html'] : '<p>' . esc_html__( 'Consulta el CHANGELOG.md incluido en el plugin.', 'dsmk-legal' ) . '</p>',
			),
		);
	}

	/**
	 * Huella esperada de un paquete, leída de los datos ya validados (sin HTTP).
	 *
	 * @since  2.3.0
	 * @param  string $package URL del paquete.
	 * @return string Hex o vacío si no es un paquete conocido.
	 */
	private static function expected_hash( $package ) {
		$updates = get_site_transient( 'update_plugins' );
		$base    = self::basename();
		if ( is_object( $updates ) && isset( $updates->response[ $base ] ) ) {
			$item = $updates->response[ $base ];
			if ( is_object( $item ) && isset( $item->package, $item->dsmkc_sha256 ) && $package === $item->package ) {
				return (string) $item->dsmkc_sha256;
			}
		}
		$cached = get_site_transient( self::CACHE );
		if ( is_array( $cached ) && isset( $cached['download_url'], $cached['sha256'] ) && $package === $cached['download_url'] ) {
			return (string) $cached['sha256'];
		}
		return '';
	}

	/**
	 * Descarga el ZIP y comprueba su SHA-256 (firmado) antes de instalarlo.
	 *
	 * Falla cerrado: si es nuestro paquete y no hay huella con la que
	 * compararlo, no se instala. El resto de descargas (núcleo, otros plugins,
	 * temas, traducciones) siguen el camino normal sin ninguna petición extra.
	 *
	 * @since  2.3.0
	 * @param  bool|string|WP_Error $reply      Respuesta previa (false = no gestionado).
	 * @param  string               $package    URL del paquete.
	 * @param  object|null          $upgrader   Instancia del actualizador (no se usa).
	 * @param  array                $hook_extra Datos de la operación (incluye 'plugin').
	 * @return bool|string|WP_Error Ruta del ZIP verificado, error o $reply.
	 */
	public static function download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		if ( false !== $reply || ! is_string( $package ) ) {
			return $reply;
		}
		$ours = is_array( $hook_extra ) && isset( $hook_extra['plugin'] ) && self::basename() === $hook_extra['plugin'];
		$sha  = self::expected_hash( $package );
		if ( ! $ours && '' === $sha ) {
			return $reply; // No es nuestro paquete.
		}
		if ( '' === $sha || 0 !== strpos( strtolower( $package ), 'https://' ) ) {
			return new WP_Error( 'dsmkc_update_hash', __( 'No hay una huella verificada para esta actualización de DSMK Legal: no se instala. Pulsa «Comprobar de nuevo» y vuelve a intentarlo.', 'dsmk-legal' ) );
		}
		$tmp = wp_tempnam( $package );
		if ( ! $tmp ) {
			return new WP_Error( 'dsmkc_update_tmp', __( 'No se pudo crear el archivo temporal de la actualización.', 'dsmk-legal' ) );
		}
		$args             = self::http_args( 300, $package );
		$args['stream']   = true;
		$args['filename'] = $tmp;
		$response         = wp_safe_remote_get( $package, $args );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'dsmkc_update_download', __( 'No se pudo descargar la actualización de DSMK Legal.', 'dsmk-legal' ) );
		}
		if ( ! hash_equals( $sha, (string) hash_file( 'sha256', $tmp ) ) ) {
			wp_delete_file( $tmp );
			self::clear();
			return new WP_Error( 'dsmkc_update_hash', __( 'La actualización de DSMK Legal no coincide con su huella SHA-256 firmada y no se ha instalado.', 'dsmk-legal' ) );
		}
		return $tmp;
	}

	/**
	 * Mantiene la carpeta del plugin al actualizar.
	 *
	 * El ZIP trae la carpeta «dsmk-legal/». Si la web lo tiene en otra (p. ej.
	 * «dsmk-legal-main/» por haberlo subido desde GitHub), WordPress borraría
	 * la antigua, instalaría en «dsmk-legal/» y desactivaría el plugin: el
	 * banner y el bloqueo dejarían de funcionar. Aquí se renombra la carpeta
	 * descomprimida a la que ya usa la web.
	 *
	 * @since  2.3.0
	 * @param  string|WP_Error $source        Carpeta descomprimida.
	 * @param  string          $remote_source Carpeta temporal que la contiene.
	 * @param  object|null     $upgrader      Actualizador (no se usa).
	 * @param  array           $hook_extra    Datos de la operación.
	 * @return string|WP_Error
	 */
	public static function source_selection( $source, $remote_source, $upgrader = null, $hook_extra = array() ) {
		global $wp_filesystem;
		if ( is_wp_error( $source ) || ! is_array( $hook_extra ) || empty( $hook_extra['plugin'] ) || self::basename() !== $hook_extra['plugin'] ) {
			return $source;
		}
		$want = dirname( self::basename() );
		if ( '.' === $want || '' === $want || basename( untrailingslashit( $source ) ) === $want ) {
			return $source;
		}
		$target = trailingslashit( $remote_source ) . $want . '/';
		if ( is_object( $wp_filesystem ) && $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $target ), true ) ) {
			return $target;
		}
		return new WP_Error( 'dsmkc_update_dir', __( 'No se pudo preparar la carpeta de la actualización de DSMK Legal.', 'dsmk-legal' ) );
	}

	/**
	 * Actualizaciones automáticas si el ajuste está activado.
	 *
	 * Con el ajuste desactivado (o en multisite) manda el interruptor de WordPress.
	 *
	 * @since  2.3.0
	 * @param  bool|null $update Decisión previa.
	 * @param  object    $item   Plugin a actualizar.
	 * @return bool|null
	 */
	public static function auto_update( $update, $item ) {
		if ( self::settings_allowed() && is_object( $item ) && isset( $item->plugin ) && self::basename() === $item->plugin && DSMKC_Settings::get( 'update_auto' ) ) {
			return true;
		}
		return $update;
	}

	/**
	 * Olvida la respuesta guardada (tras actualizar o al pedir comprobar).
	 *
	 * @since  2.3.0
	 * @return void
	 */
	public static function clear() {
		delete_site_transient( self::CACHE );
	}

	/**
	 * Comprueba ahora mismo y refresca la lista de actualizaciones de WordPress.
	 *
	 * @since  2.3.0
	 * @return array|WP_Error Igual que remote().
	 */
	public static function check_now() {
		self::clear();
		$remote = self::remote( true );
		delete_site_transient( 'update_plugins' );
		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}
		return $remote;
	}

	/**
	 * Estado para el panel (sin hacer peticiones).
	 *
	 * @since  2.3.0
	 * @return array{installed:string,available:string,checked:int,error:string,url:string,newer:bool}
	 */
	public static function status() {
		$cached = get_site_transient( self::CACHE );
		$cached = is_array( $cached ) ? $cached : array();
		$avail  = isset( $cached['version'] ) ? (string) $cached['version'] : '';
		return array(
			'installed' => DSMKC_VERSION,
			'available' => $avail,
			'checked'   => isset( $cached['checked'] ) ? (int) $cached['checked'] : 0,
			'error'     => isset( $cached['error'] ) ? (string) $cached['error'] : '',
			'url'       => self::url(),
			'newer'     => '' !== $avail && version_compare( $avail, DSMKC_VERSION, '>' ),
		);
	}
}
