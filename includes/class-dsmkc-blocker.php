<?php
/**
 * DSMK Legal — Bloqueo previo en el HTML (servidor).
 *
 * @package   DSMK_Legal
 * @file      includes/class-dsmkc-blocker.php
 * @since     1.0.0
 * @author    DSMK Agencia Digital <hola@dsmk.es>
 * @license   GPL-2.0-or-later
 *
 * Responsabilidad:
 *   Recorre el HTML final de cada página (output buffer) y neutraliza todo
 *   <script>, <iframe> e <img> de seguimiento que coincida con una regla no
 *   necesaria, ANTES de que llegue al navegador:
 *     - <script>: type="text/plain" + src → data-dsmkc-src.
 *     - <iframe>: src → data-dsmkc-src (src="about:blank").
 *     - <img> (píxeles): src → data-dsmkc-src.
 *   El JavaScript de consentimiento los restaura cuando hay consentimiento.
 *   Así se cumple el requisito de que «las cookies no técnicas y/o de terceros
 *   no se instalan hasta que el usuario otorga su consentimiento expreso».
 *
 * Complemento:
 *   Los scripts que se crean dinámicamente (GTM, iframe_api de YouTube...) los
 *   intercepta assets/js/dsmkc-head.js en el navegador.
 *
 * Seguridad / rendimiento:
 *   - Solo actúa en peticiones de front-end HTML (no admin, REST, AJAX, feeds).
 *   - Expresiones regulares acotadas; no evalúa nada del HTML.
 *   - El escáner puede desactivarlo con token secreto (hash_equals).
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bloqueador por output buffer.
 *
 * @since 1.0.0
 */
final class DSMKC_Blocker {

	/** @var string[] Tipos de script ejecutables que se analizan. */
	const JS_TYPES = array( '', 'text/javascript', 'application/javascript', 'module', 'text/ecmascript', 'application/ecmascript' );

	/** @var bool Si el buffer está activo en esta petición. */
	private $active = false;

	/** @var array<string, int> Servicios bloqueados en esta respuesta (para depuración). */
	public $blocked = array();

	/**
	 * Registra los hooks.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function hooks() {
		// Prioridad muy baja: empezar el buffer antes de que el tema imprima nada.
		add_action( 'template_redirect', array( $this, 'start' ), -9999 );
	}

	/**
	 * ¿Debe omitirse el bloqueo en esta petición?
	 *
	 * @since  1.0.0
	 * @return bool
	 */
	public static function bypass() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return true;
		}
		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return true;
		}
		if ( is_feed() || is_robots() || is_trackback() || is_customize_preview() ) {
			return true;
		}
		// Editores visuales: Elementor, Beaver Builder, Divi, Bricks, Oxygen.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Solo lectura de flags de editores.
		foreach ( array( 'elementor-preview', 'fl_builder', 'et_fb', 'bricks', 'ct_builder', 'vc_editable' ) as $flag ) {
			if ( isset( $_GET[ $flag ] ) ) {
				return true;
			}
		}
		// phpcs:enable
		if ( self::is_scan_request() ) {
			return true;
		}
		/**
		 * Permite desactivar el bloqueo en peticiones concretas.
		 *
		 * @since 1.0.0
		 * @param bool $bypass Valor actual.
		 */
		return (bool) apply_filters( 'dsmkc_bypass_blocking', false );
	}

	/**
	 * ¿Es una petición del escáner (servidor o navegador del administrador)?
	 *
	 * Seguridad: el token se compara en tiempo constante. En el navegador,
	 * además, exige un administrador con sesión iniciada.
	 *
	 * @since  1.0.0
	 * @return bool
	 */
	public static function is_scan_request() {
		static $result = null;
		if ( null !== $result ) {
			return $result;
		}
		$token  = DSMKC_Settings::scan_token();
		$header = isset( $_SERVER['HTTP_X_DSMKC_SCAN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_DSMKC_SCAN'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Autenticado por token + capacidad.
		$query  = isset( $_GET['dsmkc_scan'] ) ? sanitize_text_field( wp_unslash( $_GET['dsmkc_scan'] ) ) : '';

		$result = ( '' !== $header && hash_equals( $token, $header ) )
			|| ( '' !== $query && hash_equals( $token, $query ) && current_user_can( 'manage_options' ) );
		return $result;
	}

	/**
	 * Abre el output buffer si procede.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function start() {
		if ( $this->active || self::bypass() ) {
			return;
		}
		$this->active = true;
		ob_start( array( $this, 'process' ) );
	}

	/**
	 * Procesa el HTML completo de la respuesta.
	 *
	 * @since  1.0.0
	 * @param  string $html HTML generado por WordPress.
	 * @return string HTML con los rastreadores neutralizados.
	 */
	public function process( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		// Solo documentos HTML (evita tocar JSON, XML, ficheros...).
		if ( false === stripos( substr( $html, 0, 1000 ), '<html' ) && false === stripos( substr( $html, 0, 1000 ), '<!doctype' ) ) {
			return $html;
		}
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) && false === stripos( $header, 'html' ) ) {
				return $html;
			}
		}
		return $this->filter_html( $html );
	}

	/**
	 * Aplica los bloqueos a un fragmento o documento HTML.
	 *
	 * Público para poder reutilizarlo en pruebas y en el informe.
	 *
	 * @since  1.0.0
	 * @param  string $html HTML.
	 * @return string
	 */
	public function filter_html( $html ) {
		$out = preg_replace_callback( '#<script\b([^>]*)>(.*?)</script\s*>#is', array( $this, 'cb_script' ), $html );
		$out = is_string( $out ) ? $out : $html; // Ante error de PCRE, nunca romper la página.

		$tmp = preg_replace_callback( '#<iframe\b([^>]*)>#i', array( $this, 'cb_iframe' ), $out );
		$out = is_string( $tmp ) ? $tmp : $out;

		$tmp = preg_replace_callback( '#<img\b([^>]*)>#i', array( $this, 'cb_img' ), $out );
		$out = is_string( $tmp ) ? $tmp : $out;

		return $out;
	}

	/**
	 * Callback para cada <script>.
	 *
	 * @since  1.0.0
	 * @param  array $m Coincidencia [completo, atributos, contenido].
	 * @return string
	 */
	public function cb_script( $m ) {
		$attrs = self::parse_attrs( $m[1] );

		if ( isset( $attrs['data-dsmkc-ignore'] ) || isset( $attrs['data-dsmkc-category'] ) ) {
			return $m[0];
		}
		if ( isset( $attrs['id'] ) && 0 === strpos( (string) $attrs['id'], 'dsmkc-' ) ) {
			return $m[0];
		}
		$type = isset( $attrs['type'] ) ? strtolower( trim( (string) $attrs['type'] ) ) : '';
		if ( ! in_array( $type, self::JS_TYPES, true ) ) {
			return $m[0]; // JSON-LD, plantillas, etc.
		}

		$match = null;
		if ( ! empty( $attrs['src'] ) ) {
			$match = DSMKC_Services::match( $attrs['src'], 'src' );
		} elseif ( '' !== trim( $m[2] ) ) {
			$match = DSMKC_Services::match( $m[2], 'inline' );
		}
		if ( ! $match ) {
			return $m[0];
		}

		$this->note( $match['s'] );
		$new = $attrs;
		unset( $new['type'] );
		if ( isset( $new['src'] ) ) {
			$new['data-dsmkc-src'] = $new['src'];
			unset( $new['src'] );
		}
		if ( 'module' === $type ) {
			$new['data-dsmkc-type'] = 'module';
		}
		$new = array_merge(
			array(
				'type'                => 'text/plain',
				'data-dsmkc-category' => $match['c'],
				'data-dsmkc-service'  => $match['s'],
			),
			$new
		);
		return '<script' . self::build_attrs( $new ) . '>' . $m[2] . '</script>';
	}

	/**
	 * Callback para cada <iframe>.
	 *
	 * @since  1.0.0
	 * @param  array $m Coincidencia [completo, atributos].
	 * @return string
	 */
	public function cb_iframe( $m ) {
		$attrs = self::parse_attrs( $m[1] );
		if ( isset( $attrs['data-dsmkc-category'] ) ) {
			return $m[0];
		}
		// Algunos plugins de carga diferida usan data-src / data-lazy-src.
		$key = null;
		foreach ( array( 'src', 'data-src', 'data-lazy-src' ) as $candidate ) {
			if ( ! empty( $attrs[ $candidate ] ) && 'about:blank' !== $attrs[ $candidate ] ) {
				$key = $candidate;
				break;
			}
		}
		if ( null === $key ) {
			return $m[0];
		}
		$match = DSMKC_Services::match( $attrs[ $key ], 'iframe' );
		if ( ! $match ) {
			return $m[0];
		}

		$this->note( $match['s'] );
		$attrs['data-dsmkc-src']      = $attrs[ $key ];
		$attrs['data-dsmkc-category'] = $match['c'];
		$attrs['data-dsmkc-service']  = $match['s'];
		unset( $attrs['data-src'], $attrs['data-lazy-src'] );
		$attrs['src']   = 'about:blank';
		$attrs['class'] = trim( ( isset( $attrs['class'] ) ? $attrs['class'] : '' ) . ' dsmkc-blocked' );
		return '<iframe' . self::build_attrs( $attrs ) . '>';
	}

	/**
	 * Callback para cada <img> (solo píxeles de seguimiento conocidos).
	 *
	 * @since  1.0.0
	 * @param  array $m Coincidencia [completo, atributos].
	 * @return string
	 */
	public function cb_img( $m ) {
		if ( false === stripos( $m[1], 'src' ) ) {
			return $m[0];
		}
		$attrs = self::parse_attrs( $m[1] );
		if ( empty( $attrs['src'] ) || isset( $attrs['data-dsmkc-category'] ) ) {
			return $m[0];
		}
		$match = DSMKC_Services::match( $attrs['src'], 'img' );
		if ( ! $match ) {
			return $m[0];
		}
		$this->note( $match['s'] );
		$attrs['data-dsmkc-src']      = $attrs['src'];
		$attrs['data-dsmkc-category'] = $match['c'];
		$attrs['data-dsmkc-service']  = $match['s'];
		unset( $attrs['src'], $attrs['srcset'] );
		$self_closing = '/' === substr( rtrim( $m[1] ), -1 ) ? ' /' : '';
		return '<img' . self::build_attrs( $attrs ) . $self_closing . '>';
	}

	/**
	 * Anota un servicio bloqueado.
	 *
	 * @since  1.0.0
	 * @param  string $service Id.
	 * @return void
	 */
	private function note( $service ) {
		$this->blocked[ $service ] = isset( $this->blocked[ $service ] ) ? $this->blocked[ $service ] + 1 : 1;
	}

	/**
	 * Analiza los atributos de una etiqueta HTML.
	 *
	 * @since  1.0.0
	 * @param  string $str Cadena de atributos.
	 * @return array<string, string|true> nombre en minúsculas => valor (true si no tiene valor).
	 */
	public static function parse_attrs( $str ) {
		$attrs = array();
		$str   = rtrim( (string) $str, " \t\n\r/" );
		if ( preg_match_all( '#([^\s=/"\'>]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?#', $str, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$name = strtolower( $match[1] );
				if ( isset( $match[4] ) && '' !== $match[4] ) {
					$value = $match[4];
				} elseif ( isset( $match[3] ) && '' !== $match[3] ) {
					$value = $match[3];
				} elseif ( isset( $match[2] ) && ( '' !== $match[2] || false !== strpos( $match[0], '=' ) ) ) {
					$value = $match[2];
				} else {
					$value = true;
				}
				if ( ! isset( $attrs[ $name ] ) ) {
					$attrs[ $name ] = $value;
				}
			}
		}
		return $attrs;
	}

	/**
	 * Reconstruye una cadena de atributos.
	 *
	 * Los valores conservan su codificación original (ya venían escapados en
	 * el HTML); solo se escapan comillas dobles para no romper el atributo.
	 *
	 * @since  1.0.0
	 * @param  array $attrs Atributos.
	 * @return string
	 */
	public static function build_attrs( array $attrs ) {
		$out = '';
		foreach ( $attrs as $name => $value ) {
			if ( true === $value ) {
				$out .= ' ' . $name;
			} else {
				$out .= ' ' . $name . '="' . str_replace( '"', '&quot;', (string) $value ) . '"';
			}
		}
		return $out;
	}

	/**
	 * Analiza un HTML sin modificarlo: lista los servicios presentes y si se
	 * cargarían sin consentimiento (para el escáner y el informe).
	 *
	 * @since  1.0.0
	 * @param  string $html HTML.
	 * @return array{services:array<string,string>, leaks:array<int,array{service:string, tag:string, url:string}>}
	 *         services: id => categoría; leaks: rastreadores NO bloqueados.
	 */
	public static function analyze( $html ) {
		$services = array();
		$leaks    = array();

		if ( preg_match_all( '#<script\b([^>]*)>(.*?)</script\s*>#is', $html, $scripts, PREG_SET_ORDER ) ) {
			foreach ( $scripts as $m ) {
				$attrs = self::parse_attrs( $m[1] );
				if ( isset( $attrs['data-dsmkc-ignore'] ) || ( isset( $attrs['id'] ) && 0 === strpos( (string) $attrs['id'], 'dsmkc-' ) ) ) {
					continue;
				}
				$type    = isset( $attrs['type'] ) ? strtolower( (string) $attrs['type'] ) : '';
				$src     = isset( $attrs['data-dsmkc-src'] ) ? $attrs['data-dsmkc-src'] : ( isset( $attrs['src'] ) ? $attrs['src'] : '' );
				$kind    = '' !== $src ? 'src' : 'inline';
				$subject = '' !== $src ? $src : $m[2];
				if ( 'text/plain' !== $type && ! in_array( $type, self::JS_TYPES, true ) ) {
					continue;
				}
				$match = DSMKC_Services::match( $subject, $kind, true );
				if ( ! $match ) {
					continue;
				}
				$services[ $match['s'] ] = $match['c'];
				if ( 'necessary' !== $match['c'] && 'text/plain' !== $type ) {
					$leaks[] = array( 'service' => $match['s'], 'tag' => 'script', 'url' => 'src' === $kind ? $src : substr( trim( wp_strip_all_tags( $m[2] ) ), 0, 80 ) );
				}
			}
		}

		foreach ( array( 'iframe', 'img' ) as $tag ) {
			if ( ! preg_match_all( '#<' . $tag . '\b([^>]*)>#i', $html, $found, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $found as $m ) {
				$attrs = self::parse_attrs( $m[1] );
				$src   = '';
				foreach ( array( 'data-dsmkc-src', 'src', 'data-src', 'data-lazy-src' ) as $key ) {
					if ( ! empty( $attrs[ $key ] ) && 'about:blank' !== $attrs[ $key ] && true !== $attrs[ $key ] ) {
						$src = $attrs[ $key ];
						break;
					}
				}
				if ( '' === $src ) {
					continue;
				}
				$match = DSMKC_Services::match( $src, $tag, true );
				if ( ! $match ) {
					continue;
				}
				$services[ $match['s'] ] = $match['c'];
				$executable              = ! isset( $attrs['data-dsmkc-src'] );
				if ( 'necessary' !== $match['c'] && $executable ) {
					$leaks[] = array( 'service' => $match['s'], 'tag' => $tag, 'url' => $src );
				}
			}
		}

		return array( 'services' => $services, 'leaks' => $leaks );
	}
}
