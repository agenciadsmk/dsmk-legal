<?php
/**
 * DSMK Legal — Servicios, categorías y reglas de bloqueo.
 *
 * @package   DSMK_Legal
 * @file      includes/class-dsmkc-services.php
 * @since     1.0.0
 * @author    DSMK Agencia Digital <hola@dsmk.es>
 * @license   GPL-2.0-or-later
 *
 * Responsabilidad:
 *   - Cargar el catálogo de servicios conocidos (includes/data/services.php).
 *   - Calcular los servicios ACTIVOS de la web (detectados + integraciones +
 *     siempre presentes), con las categorías corregidas por el usuario.
 *   - Generar las REGLAS DE BLOQUEO: todos los servicios no necesarios del
 *     catálogo (detectados o no) + reglas adicionales del usuario. Se bloquea
 *     por defecto todo lo conocido, aunque el escáner aún no lo haya visto.
 *   - Resolver a qué servicio pertenece una URL o una cookie.
 *
 * Seguridad:
 *   Las reglas son subcadenas literales (no expresiones regulares del usuario),
 *   así se evita ReDoS y la inyección de patrones.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Catálogo y lógica de servicios.
 *
 * @since 1.0.0
 */
final class DSMKC_Services {

	/** @var string[] Categorías en orden de presentación. */
	const CATEGORIES = array( 'necessary', 'preferences', 'analytics', 'marketing' );

	/** @var array|null Caché del catálogo. */
	private static $catalogue = null;

	/** @var array|null Caché de las reglas de bloqueo. */
	private static $rules = null;

	/**
	 * Catálogo completo de servicios conocidos.
	 *
	 * @since  1.0.0
	 * @return array<string, array>
	 */
	public static function catalogue() {
		if ( null === self::$catalogue ) {
			$list = require DSMKC_DIR . 'includes/data/services.php';
			foreach ( $list as $id => $service ) {
				$list[ $id ] = wp_parse_args(
					$service,
					array( 'src' => array(), 'inline' => array(), 'iframe' => array(), 'img' => array(), 'cookies' => array(), 'privacy' => '', 'transfer' => '' )
				);
			}
			/**
			 * Permite añadir o modificar servicios del catálogo.
			 *
			 * @since 1.0.0
			 * @param array $list Catálogo.
			 */
			self::$catalogue = apply_filters( 'dsmkc_services_catalogue', $list );
		}
		return self::$catalogue;
	}

	/**
	 * Categoría efectiva de un servicio (con correcciones del usuario y de ajustes).
	 *
	 * @since  1.0.0
	 * @param  string $id Id del servicio.
	 * @return string Categoría o 'excluded'.
	 */
	public static function category_of( $id ) {
		$overrides = DSMKC_Settings::data()['overrides'];
		if ( isset( $overrides[ $id ] ) && ( in_array( $overrides[ $id ], self::CATEGORIES, true ) || 'excluded' === $overrides[ $id ] ) ) {
			return $overrides[ $id ];
		}
		if ( 'gtm' === $id ) {
			return DSMKC_Settings::get( 'gtm_categoria', 'analytics' );
		}
		$cat = self::catalogue();
		return isset( $cat[ $id ] ) ? $cat[ $id ]['category'] : 'marketing';
	}

	/**
	 * Servicios activos de la web (los que se documentan y se muestran en el panel).
	 *
	 * Incluye: los detectados por el escáner, los implicados por las
	 * integraciones configuradas y los que siempre existen (WordPress y el
	 * propio gestor de consentimiento). Excluye los marcados como 'excluded'.
	 *
	 * @since  1.0.0
	 * @return array<string, array> id => servicio (con 'category' efectiva).
	 */
	public static function active() {
		$catalogue = self::catalogue();
		$data      = DSMKC_Settings::data();
		$ids       = array( 'dsmk_cookies', 'wordpress' );

		foreach ( array_keys( $data['detected'] ) as $id ) {
			$ids[] = $id;
		}

		$map = array(
			'ga4_id'     => 'ga4',
			'gtm_id'     => 'gtm',
			'ads_id'     => 'google_ads',
			'pixel_id'   => 'meta_pixel',
			'clarity_id' => 'clarity',
		);
		foreach ( $map as $setting => $id ) {
			if ( '' !== (string) DSMKC_Settings::get( $setting, '' ) ) {
				$ids[] = $id;
			}
		}
		if ( DSMKC_Settings::get( 'wp_consent_api' ) && function_exists( 'wp_set_consent' ) ) {
			$ids[] = 'wp_consent_api';
		}

		$out = array();
		foreach ( array_unique( $ids ) as $id ) {
			if ( ! isset( $catalogue[ $id ] ) ) {
				continue;
			}
			$category = self::category_of( $id );
			if ( 'excluded' === $category ) {
				continue;
			}
			$out[ $id ]             = $catalogue[ $id ];
			$out[ $id ]['category'] = $category;
		}

		// Servicios "virtuales" definidos por el usuario (reglas adicionales).
		foreach ( self::extra_rules() as $rule ) {
			$out[ $rule['service'] ] = array(
				'name'     => $rule['name'],
				'provider' => $rule['name'],
				'category' => $rule['category'],
				'privacy'  => '',
				'transfer' => '',
				'src'      => array( $rule['pattern'] ),
				'inline'   => array(),
				'iframe'   => array( $rule['pattern'] ),
				'img'      => array( $rule['pattern'] ),
				'cookies'  => array(),
			);
		}

		/**
		 * Filtra los servicios activos.
		 *
		 * @since 1.0.0
		 * @param array $out Servicios activos.
		 */
		return apply_filters( 'dsmkc_active_services', $out );
	}

	/**
	 * Reglas de bloqueo adicionales escritas por el usuario en «Avanzado».
	 *
	 * Formato por línea: «patrón | categoría | nombre».
	 *
	 * @since  1.0.0
	 * @return array<int, array{pattern:string, category:string, name:string, service:string}>
	 */
	public static function extra_rules() {
		$raw   = (string) DSMKC_Settings::get( 'patrones_extra', '' );
		$rules = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $i => $line ) {
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( empty( $parts[0] ) || strlen( $parts[0] ) < 4 ) {
				continue; // Evita patrones demasiado genéricos (p. ej. «.js»).
			}
			$category = isset( $parts[1] ) && in_array( $parts[1], array( 'preferences', 'analytics', 'marketing' ), true ) ? $parts[1] : 'marketing';
			$name     = ! empty( $parts[2] ) ? $parts[2] : $parts[0];
			$rules[]  = array(
				'pattern'  => $parts[0],
				'category' => $category,
				'name'     => $name,
				'service'  => 'custom_' . substr( md5( $parts[0] ), 0, 8 ),
			);
		}
		return $rules;
	}

	/**
	 * Reglas de bloqueo para el HTML y para el interceptor JavaScript.
	 *
	 * Se incluyen TODOS los servicios no necesarios del catálogo (aunque no se
	 * hayan detectado todavía) para que nada se cuele entre escaneos.
	 *
	 * @since  1.0.0
	 * @return array<int, array{s:string, c:string, src:array, inline:array, iframe:array, img:array}>
	 */
	public static function rules() {
		if ( null !== self::$rules ) {
			return self::$rules;
		}
		$rules = array();
		foreach ( self::catalogue() as $id => $service ) {
			$category = self::category_of( $id );
			if ( 'necessary' === $category || 'excluded' === $category ) {
				continue;
			}
			$rules[] = array(
				's'      => $id,
				'c'      => $category,
				'src'    => array_values( $service['src'] ),
				'inline' => array_values( $service['inline'] ),
				'iframe' => array_values( $service['iframe'] ),
				'img'    => array_values( $service['img'] ),
			);
		}
		foreach ( self::extra_rules() as $rule ) {
			array_unshift(
				$rules,
				array(
					's'      => $rule['service'],
					'c'      => $rule['category'],
					'src'    => array( $rule['pattern'] ),
					'inline' => array( $rule['pattern'] ),
					'iframe' => array( $rule['pattern'] ),
					'img'    => array( $rule['pattern'] ),
				)
			);
		}
		self::$rules = $rules;
		return $rules;
	}

	/**
	 * Vacía la caché estática de reglas (pruebas y cambios en caliente).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function reset() {
		self::$catalogue = null;
		self::$rules     = null;
	}

	/**
	 * Busca qué servicio (de cualquier categoría) corresponde a una URL o código.
	 *
	 * @since  1.0.0
	 * @param  string $haystack URL o contenido de script.
	 * @param  string $kind     src | inline | iframe | img.
	 * @param  bool   $all      true = incluir servicios necesarios (para el escáner).
	 * @return array{s:string, c:string}|null
	 */
	public static function match( $haystack, $kind, $all = false ) {
		$haystack = html_entity_decode( (string) $haystack, ENT_QUOTES );
		if ( '' === $haystack ) {
			return null;
		}

		$sources = array();
		if ( $all ) {
			foreach ( self::catalogue() as $id => $service ) {
				$sources[] = array(
					's'      => $id,
					'c'      => self::category_of( $id ),
					'src'    => $service['src'],
					'inline' => $service['inline'],
					'iframe' => $service['iframe'],
					'img'    => $service['img'],
				);
			}
			foreach ( self::extra_rules() as $rule ) {
				array_unshift( $sources, array( 's' => $rule['service'], 'c' => $rule['category'], 'src' => array( $rule['pattern'] ), 'inline' => array( $rule['pattern'] ), 'iframe' => array( $rule['pattern'] ), 'img' => array( $rule['pattern'] ) ) );
			}
		} else {
			$sources = self::rules();
		}

		foreach ( $sources as $rule ) {
			if ( 'excluded' === $rule['c'] ) {
				continue;
			}
			foreach ( $rule[ $kind ] as $needle ) {
				if ( '' !== $needle && false !== stripos( $haystack, $needle ) ) {
					return array( 's' => $rule['s'], 'c' => $rule['c'] );
				}
			}
		}
		return null;
	}

	/**
	 * Convierte un nombre de cookie con comodines (*) en expresión regular.
	 *
	 * @since  1.0.0
	 * @param  string $pattern Nombre con comodines.
	 * @return string Regex delimitada.
	 */
	public static function cookie_regex( $pattern ) {
		return '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/';
	}

	/**
	 * Busca el servicio al que pertenece una cookie por su nombre.
	 *
	 * @since  1.0.0
	 * @param  string $name Nombre de la cookie.
	 * @return string|null Id del servicio.
	 */
	public static function service_for_cookie( $name ) {
		foreach ( self::catalogue() as $id => $service ) {
			foreach ( $service['cookies'] as $cookie ) {
				if ( preg_match( self::cookie_regex( $cookie[0] ), $name ) ) {
					return $id;
				}
			}
		}
		return null;
	}

	/**
	 * ¿Está la cookie documentada a mano por el usuario?
	 *
	 * @since  1.0.0
	 * @param  string $name Nombre.
	 * @return bool
	 */
	public static function is_manual( $name ) {
		foreach ( DSMKC_Settings::data()['manual'] as $row ) {
			if ( preg_match( self::cookie_regex( $row['name'] ), $name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Nombres visibles y descripciones de las categorías.
	 *
	 * @since  1.0.0
	 * @return array<string, array{name:string, desc:string}>
	 */
	public static function category_labels() {
		$defaults = array(
			'necessary'   => array(
				__( 'Técnicas (necesarias)', 'dsmk-legal' ),
				__( 'Imprescindibles para que la web funcione: seguridad, sesión, carrito, idioma elegido o recordar tu decisión sobre las cookies. No requieren consentimiento y no se pueden desactivar.', 'dsmk-legal' ),
			),
			'preferences' => array(
				__( 'Preferencias', 'dsmk-legal' ),
				__( 'Permiten recordar opciones que cambian el aspecto o el comportamiento de la web y ofrecer funciones como chats o reservas de terceros.', 'dsmk-legal' ),
			),
			'analytics'   => array(
				__( 'Analíticas', 'dsmk-legal' ),
				__( 'Nos permiten contar las visitas y saber cómo se usa la web (páginas vistas, tiempo, origen) para mejorarla. La información se trata de forma agregada.', 'dsmk-legal' ),
			),
			'marketing'   => array(
				__( 'Publicidad y contenidos de terceros', 'dsmk-legal' ),
				__( 'Las usan redes publicitarias y plataformas como Google, Meta o YouTube para mostrarte anuncios según un perfil elaborado a partir de tus hábitos de navegación y para medir campañas. También se activan al ver vídeos, mapas o publicaciones incrustadas.', 'dsmk-legal' ),
			),
		);
		$out = array();
		foreach ( $defaults as $key => $pair ) {
			$name        = trim( (string) DSMKC_Settings::get( 'cat_' . $key . '_nombre', '' ) );
			$desc        = trim( (string) DSMKC_Settings::get( 'cat_' . $key . '_desc', '' ) );
			$out[ $key ] = array(
				'name' => '' !== $name ? $name : $pair[0],
				'desc' => '' !== $desc ? $desc : $pair[1],
			);
		}
		return $out;
	}

	/**
	 * Agrupa servicios activos por categoría (categorías vacías omitidas,
	 * salvo «necessary» que siempre aparece).
	 *
	 * @since  1.0.0
	 * @return array<string, array<string, array>>
	 */
	public static function by_category() {
		$out = array_fill_keys( self::CATEGORIES, array() );
		foreach ( self::active() as $id => $service ) {
			$out[ $service['category'] ][ $id ] = $service;
		}
		foreach ( DSMKC_Settings::data()['manual'] as $row ) {
			if ( empty( $out[ $row['category'] ]['manual_' . $row['category']] ) && 'necessary' !== $row['category'] ) {
				$out[ $row['category'] ][ 'manual_' . $row['category'] ] = array(
					'name'     => __( 'Otras cookies de la web', 'dsmk-legal' ),
					'provider' => '',
					'category' => $row['category'],
					'cookies'  => array(),
					'privacy'  => '',
					'transfer' => '',
				);
			}
		}
		return array_filter(
			$out,
			static function ( $list, $cat ) {
				return 'necessary' === $cat || ! empty( $list );
			},
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Filas de la tabla de cookies (para la política y el informe).
	 *
	 * @since  1.0.0
	 * @return array<int, array{name:string, service:string, provider:string, party:string, purpose:string, duration:string, category:string, privacy:string}>
	 */
	public static function cookie_rows() {
		$rows     = array();
		$duration = self::consent_duration_label();
		foreach ( self::active() as $id => $service ) {
			foreach ( $service['cookies'] as $cookie ) {
				$rows[] = array(
					'name'     => $cookie[0],
					'service'  => $service['name'],
					'provider' => $service['provider'],
					'party'    => 'propia' === $cookie[3] ? __( 'Propia', 'dsmk-legal' ) : __( 'De terceros', 'dsmk-legal' ),
					'purpose'  => $cookie[2],
					'duration' => str_replace( '{consent_duration}', $duration, $cookie[1] ),
					'category' => $service['category'],
					'privacy'  => $service['privacy'],
				);
			}
		}
		foreach ( DSMKC_Settings::data()['manual'] as $row ) {
			$rows[] = array(
				'name'     => $row['name'],
				'service'  => $row['provider'],
				'provider' => $row['provider'],
				'party'    => 'propia' === $row['party'] ? __( 'Propia', 'dsmk-legal' ) : __( 'De terceros', 'dsmk-legal' ),
				'purpose'  => $row['purpose'],
				'duration' => $row['duration'],
				'category' => $row['category'],
				'privacy'  => isset( $row['privacy'] ) ? $row['privacy'] : '',
			);
		}
		return $rows;
	}

	/**
	 * Texto legible de la validez del consentimiento.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	public static function consent_duration_label() {
		$days = (int) DSMKC_Settings::get( 'consent_dias', 365 );
		if ( 0 === $days % 365 ) {
			$years = $days / 365;
			/* translators: %d: años. */
			return sprintf( _n( '%d año', '%d años', $years, 'dsmk-legal' ), $years );
		}
		if ( $days >= 30 && 0 === $days % 30 ) {
			/* translators: %d: meses. */
			return sprintf( _n( '%d mes', '%d meses', $days / 30, 'dsmk-legal' ), $days / 30 );
		}
		/* translators: %d: días. */
		return sprintf( _n( '%d día', '%d días', $days, 'dsmk-legal' ), $days );
	}

	/**
	 * Huella de los servicios no necesarios activos (para detectar cambios).
	 *
	 * @since  1.0.0
	 * @return string
	 */
	public static function hash() {
		$ids = array();
		foreach ( self::active() as $id => $service ) {
			if ( 'necessary' !== $service['category'] ) {
				$ids[] = $id . ':' . $service['category'];
			}
		}
		sort( $ids );
		return md5( implode( ',', $ids ) );
	}
}
