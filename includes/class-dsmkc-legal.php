<?php
/**
 * DSMK Legal — Textos legales, páginas y shortcodes.
 *
 * @package   DSMK_Legal
 * @file      includes/class-dsmkc-legal.php
 * @since     1.0.0
 * @author    DSMK Agencia Digital <hola@dsmk.es>
 * @license   GPL-2.0-or-later
 *
 * Responsabilidad:
 *   - Textos del banner (automáticos según servicios activos o personalizados)
 *     y huella (hash) de la versión mostrada, con histórico como prueba.
 *   - Política de cookies con todos los apartados que exige la LSSI y la Guía
 *     de la AEPD: definición y función, tipos según entidad / finalidad /
 *     plazo, tabla de cookies (nombre, titular, propia o de terceros,
 *     finalidad, duración), terceros y transferencias, cómo aceptar /
 *     rechazar / revocar y fecha de actualización.
 *   - Política de privacidad (art. 13 RGPD) y aviso legal (art. 10 LSSI)
 *     generados con los datos del titular.
 *   - Creación automática de las páginas y shortcodes:
 *       [dsmk_politica_cookies] [dsmk_politica_privacidad] [dsmk_aviso_legal]
 *       [dsmk_cookies_tabla] [dsmk_cookies_ajustes texto="..."] [dsmk_enlaces_legales]
 *
 * Aviso: los textos son plantillas profesionales generadas a partir de la
 * configuración; no sustituyen la revisión de un asesor legal en casos
 * especiales (tratamientos de datos de salud, menores, perfilado intensivo...).
 *
 * Seguridad: toda variable se escapa con esc_html()/esc_url() al componer el HTML.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Generador de textos legales.
 *
 * @since 1.0.0
 */
final class DSMKC_Legal {

	/**
	 * Registra shortcodes y hooks.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function hooks() {
		add_shortcode( 'dsmk_politica_cookies', array( __CLASS__, 'cookie_policy' ) );
		add_shortcode( 'dsmk_politica_privacidad', array( __CLASS__, 'privacy_policy' ) );
		add_shortcode( 'dsmk_aviso_legal', array( __CLASS__, 'legal_notice' ) );
		add_shortcode( 'dsmk_cookies_tabla', array( __CLASS__, 'cookie_tables' ) );
		add_shortcode( 'dsmk_cookies_ajustes', array( __CLASS__, 'settings_link' ) );
		add_shortcode( 'dsmk_enlaces_legales', array( __CLASS__, 'links_markup' ) );
	}

	/* =====================================================================
	 * Utilidades
	 * ===================================================================== */

	/**
	 * Valor del titular escapado o marcador visible de dato pendiente.
	 *
	 * @since  1.0.0
	 * @param  string $key   Clave del ajuste.
	 * @param  string $label Texto del marcador si está vacío.
	 * @return string HTML.
	 */
	private static function v( $key, $label ) {
		$value = trim( (string) DSMKC_Settings::get( $key, '' ) );
		if ( '' === $value ) {
			/* translators: %s: dato pendiente. */
			return '<mark class="dsmkc-pending">' . esc_html( sprintf( __( '[pendiente: %s]', 'dsmk-legal' ), $label ) ) . '</mark>';
		}
		return esc_html( $value );
	}

	/**
	 * Nombre de la web.
	 *
	 * @since  1.0.0
	 * @return string Texto plano.
	 */
	public static function site_name() {
		$name = wp_strip_all_tags( get_bloginfo( 'name' ) );
		return '' !== $name ? $name : wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/**
	 * Dominio de la web.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	public static function domain() {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/**
	 * URL de una de las páginas legales.
	 *
	 * @since  1.0.0
	 * @param  string $type aviso | privacidad | cookies.
	 * @return string URL o ''.
	 */
	public static function page_url( $type ) {
		$doc = DSMKC_Documents::get( $type );
		if ( ! $doc || empty( $doc['setting'] ) ) {
			return '';
		}
		if ( ! empty( $doc['toggle'] ) && ! DSMKC_Settings::get( $doc['toggle'] ) && empty( $doc['external'] ) ) {
			return ''; // Documento desactivado: no se enlaza.
		}
		$id = (int) DSMKC_Settings::get( $doc['setting'], 0 );
		if ( $id && 'publish' === get_post_status( $id ) ) {
			return (string) get_permalink( $id );
		}
		return '';
	}

	/**
	 * Enlace HTML a una página legal (o texto plano si no existe).
	 *
	 * @since  1.0.0
	 * @param  string $type  Tipo de página.
	 * @param  string $label Texto del enlace.
	 * @return string HTML.
	 */
	private static function link( $type, $label ) {
		$url = self::page_url( $type );
		return $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>' : esc_html( $label );
	}

	/**
	 * Fecha de la última actualización de la política, formateada.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	private static function updated_date() {
		$data = DSMKC_Settings::data();
		$ts   = max( (int) $data['updated'], (int) $data['last_scan'] );
		return $ts ? wp_date( get_option( 'date_format', 'j \d\e F \d\e Y' ), $ts ) : wp_date( get_option( 'date_format', 'j \d\e F \d\e Y' ) );
	}

	/**
	 * Proveedores de terceros (únicos) de los servicios activos no propios.
	 *
	 * @since  1.0.0
	 * @param  bool $only_optional true = solo servicios que requieren consentimiento.
	 * @return array<string, array{provider:string, services:string[], privacy:string, transfer:string}>
	 */
	public static function third_parties( $only_optional = false ) {
		$out = array();
		foreach ( DSMKC_Services::active() as $service ) {
			if ( empty( $service['provider'] ) || 0 === strpos( $service['provider'], 'Propia' ) ) {
				continue;
			}
			if ( $only_optional && 'necessary' === $service['category'] ) {
				continue;
			}
			$key = $service['provider'];
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = array( 'provider' => $key, 'services' => array(), 'privacy' => $service['privacy'], 'transfer' => $service['transfer'] );
			}
			$out[ $key ]['services'][] = $service['name'];
		}
		return $out;
	}

	/* =====================================================================
	 * Banner
	 * ===================================================================== */

	/**
	 * Textos del banner (personalizados o automáticos).
	 *
	 * @since  1.0.0
	 * @return array{title:string, text:string, accept:string, reject:string, settings:string, save:string, panel_intro:string}
	 */
	public static function banner_texts() {
		$s = DSMKC_Settings::all();

		$categories = array();
		foreach ( DSMKC_Services::by_category() as $cat => $list ) {
			if ( 'necessary' !== $cat ) {
				$categories[] = $cat;
			}
		}
		$purposes = array(
			'preferences' => __( 'recordar tus preferencias y ofrecerte funciones de terceros como chats o reservas', 'dsmk-legal' ),
			'analytics'   => __( 'analizar de forma estadística el uso de la web y mejorarla', 'dsmk-legal' ),
			'marketing'   => __( 'mostrarte publicidad personalizada a partir de un perfil elaborado con tus hábitos de navegación (por ejemplo, páginas visitadas), medir campañas y mostrar contenidos de terceros como vídeos o mapas', 'dsmk-legal' ),
		);
		$list = array();
		foreach ( $categories as $cat ) {
			$list[] = $purposes[ $cat ];
		}
		$finalidades = self::human_list( $list );
		$terceros    = self::human_list( array_keys( self::third_parties( true ) ) );
		$titular     = '' !== trim( $s['titular_nombre'] ) ? $s['titular_nombre'] : self::site_name();
		$politica    = self::link( 'cookies', __( 'política de cookies', 'dsmk-legal' ) );

		$custom = trim( (string) $s['banner_texto'] );
		if ( '' !== $custom ) {
			$text = strtr(
				wpautop( $custom ),
				array(
					'{titular}'     => esc_html( $titular ),
					'{finalidades}' => esc_html( $finalidades ),
					'{terceros}'    => esc_html( $terceros ),
					'{politica}'    => $politica,
				)
			);
		} elseif ( $categories ) {
			$text = '<p>' . sprintf(
				/* translators: 1: titular, 2: finalidades. */
				esc_html__( 'En %1$s utilizamos cookies propias y de terceros para %2$s.', 'dsmk-legal' ),
				'<strong>' . esc_html( $titular ) . '</strong>',
				esc_html( $finalidades )
			);
			if ( '' !== $terceros ) {
				/* translators: %s: lista de terceros. */
				$text .= ' ' . sprintf( esc_html__( 'Pueden instalarlas: %s.', 'dsmk-legal' ), esc_html( $terceros ) );
			}
			$text .= '</p><p>' . sprintf(
				/* translators: %s: enlace a la política de cookies. */
				esc_html__( 'Puedes aceptarlas todas, rechazarlas todas o elegir cuáles permites en «Configurar», y cambiar tu decisión cuando quieras. Más información en nuestra %s.', 'dsmk-legal' ),
				$politica
			) . '</p>';
		} else {
			$text = '<p>' . sprintf(
				/* translators: %s: titular. */
				esc_html__( 'En %s solo utilizamos cookies técnicas, necesarias para que la web funcione, que no requieren tu consentimiento. Si incorporamos otras cookies solo se instalarán si las aceptas.', 'dsmk-legal' ),
				'<strong>' . esc_html( $titular ) . '</strong>'
			) . '</p><p>' . sprintf(
				/* translators: %s: enlace a la política de cookies. */
				esc_html__( 'Más información en nuestra %s.', 'dsmk-legal' ),
				$politica
			) . '</p>';
		}

		$or = static function ( $value, $fallback ) {
			$value = trim( (string) $value );
			return '' !== $value ? $value : $fallback;
		};

		return array(
			'title'       => $or( $s['banner_titulo'], __( 'Usamos cookies', 'dsmk-legal' ) ),
			'text'        => $text,
			'accept'      => $or( $s['btn_aceptar'], __( 'Aceptar todas', 'dsmk-legal' ) ),
			'reject'      => $or( $s['btn_rechazar'], __( 'Rechazar todas', 'dsmk-legal' ) ),
			'settings'    => $or( $s['btn_configurar'], __( 'Configurar', 'dsmk-legal' ) ),
			'save'        => $or( $s['btn_guardar'], __( 'Guardar mi selección', 'dsmk-legal' ) ),
			'panel_intro' => sprintf(
				/* translators: %s: enlace a la política de cookies. */
				esc_html__( 'Elige qué cookies permites. Las técnicas son necesarias y están siempre activas; el resto solo se instalan si las activas. Puedes cambiar tu elección en cualquier momento. Más información en la %s.', 'dsmk-legal' ),
				$politica
			),
		);
	}

	/**
	 * Huella corta de la versión del banner (textos + versión de la política).
	 *
	 * @since  1.0.0
	 * @return string 12 caracteres hexadecimales.
	 */
	public static function banner_hash() {
		$texts = self::banner_texts();
		return substr( md5( wp_json_encode( $texts ) . '|' . DSMKC_Settings::data()['policy_version'] ), 0, 12 );
	}

	/**
	 * Guarda en el histórico la versión actual del banner (prueba de lo mostrado).
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function record_banner_version() {
		$hash    = self::banner_hash();
		$data    = DSMKC_Settings::data();
		$history = (array) $data['banner_history'];
		if ( isset( $history[ $hash ] ) ) {
			return;
		}
		$texts            = self::banner_texts();
		$history[ $hash ] = array(
			'date'    => time(),
			'version' => (int) $data['policy_version'],
			'texts'   => array_map( 'wp_strip_all_tags', $texts ),
		);
		if ( count( $history ) > 100 ) {
			$history = array_slice( $history, -100, null, true );
		}
		DSMKC_Settings::update_data( array( 'banner_history' => $history ) );
	}

	/**
	 * Une una lista en lenguaje natural: «a, b y c».
	 *
	 * @since  1.0.0
	 * @param  string[] $items Elementos.
	 * @return string
	 */
	public static function human_list( array $items ) {
		$items = array_values( array_filter( array_unique( $items ) ) );
		if ( count( $items ) < 2 ) {
			return implode( '', $items );
		}
		$last = array_pop( $items );
		return implode( ', ', $items ) . ' ' . __( 'y', 'dsmk-legal' ) . ' ' . $last;
	}

	/* =====================================================================
	 * Shortcodes auxiliares
	 * ===================================================================== */

	/**
	 * [dsmk_cookies_ajustes texto="Configurar cookies"]: enlace que abre el panel.
	 *
	 * @since  1.0.0
	 * @param  array|string $atts Atributos.
	 * @return string HTML.
	 */
	public static function settings_link( $atts = array() ) {
		$atts = shortcode_atts( array( 'texto' => __( 'Configurar cookies', 'dsmk-legal' ) ), $atts, 'dsmk_cookies_ajustes' );
		return '<a href="#dsmk-cookies" class="dsmkc-open-settings" role="button">' . esc_html( $atts['texto'] ) . '</a>';
	}

	/**
	 * [dsmk_enlaces_legales]: enlaces del pie.
	 *
	 * @since  1.0.0
	 * @return string HTML.
	 */
	public static function links_markup() {
		$parts = array();
		foreach ( DSMKC_Documents::enabled() as $type => $doc ) {
			$url = self::page_url( $type );
			if ( $url ) {
				$parts[] = '<a href="' . esc_url( $url ) . '">' . esc_html( $doc['footer'] ) . '</a>';
			}
		}
		$parts[] = self::settings_link();
		return '<nav class="dsmkc-legal-links" aria-label="' . esc_attr__( 'Información legal', 'dsmk-legal' ) . '">' . implode( ' <span aria-hidden="true">·</span> ', $parts ) . '</nav>';
	}

	/**
	 * [dsmk_cookies_tabla]: tablas de cookies por categoría.
	 *
	 * @since  1.0.0
	 * @return string HTML.
	 */
	public static function cookie_tables() {
		$labels = DSMKC_Services::category_labels();
		$rows   = DSMKC_Services::cookie_rows();
		$html   = '';
		foreach ( DSMKC_Services::CATEGORIES as $cat ) {
			$cat_rows = array_filter(
				$rows,
				static function ( $row ) use ( $cat ) {
					return $row['category'] === $cat;
				}
			);
			if ( ! $cat_rows ) {
				continue;
			}
			$html .= '<h3>' . esc_html( $labels[ $cat ]['name'] ) . '</h3>';
			$html .= '<p>' . esc_html( $labels[ $cat ]['desc'] ) . '</p>';
			$html .= '<div class="dsmkc-table-scroll"><table class="dsmkc-policy-table"><thead><tr>';
			foreach ( array( __( 'Cookie', 'dsmk-legal' ), __( 'Titular', 'dsmk-legal' ), __( 'Tipo', 'dsmk-legal' ), __( 'Finalidad', 'dsmk-legal' ), __( 'Duración', 'dsmk-legal' ) ) as $th ) {
				$html .= '<th scope="col">' . esc_html( $th ) . '</th>';
			}
			$html .= '</tr></thead><tbody>';
			foreach ( $cat_rows as $row ) {
				$provider = 0 === strpos( (string) $row['provider'], 'Propia' ) ? self::site_name() : $row['provider'];
				$html    .= '<tr><td><code>' . esc_html( $row['name'] ) . '</code></td><td>' . esc_html( $provider ) . ( 'Propia' !== $row['provider'] && $row['service'] !== $row['provider'] ? '<br><small>' . esc_html( $row['service'] ) . '</small>' : '' ) . '</td><td>' . esc_html( $row['party'] ) . '</td><td>' . esc_html( $row['purpose'] ) . '</td><td>' . esc_html( $row['duration'] ) . '</td></tr>';
			}
			$html .= '</tbody></table></div>';
		}
		return $html;
	}

	/* =====================================================================
	 * Política de cookies
	 * ===================================================================== */

	/**
	 * [dsmk_politica_cookies]
	 *
	 * @since  1.0.0
	 * @return string HTML.
	 */
	public static function cookie_policy() {
		$site     = esc_html( self::site_name() );
		$domain   = esc_html( self::domain() );
		$duration = esc_html( DSMKC_Services::consent_duration_label() );
		$third    = self::third_parties();

		ob_start();
		?>
<div class="dsmkc-legal dsmkc-legal--cookies">
<p><?php printf( esc_html__( 'Esta política explica qué son las cookies, qué tipos existen, cuáles se utilizan en %1$s (%2$s), con qué finalidad y cómo puedes aceptarlas, rechazarlas o retirar tu consentimiento en cualquier momento, de acuerdo con el artículo 22.2 de la Ley 34/2002, de servicios de la sociedad de la información y de comercio electrónico (LSSI), el Reglamento (UE) 2016/679 (RGPD) y la Guía sobre el uso de las cookies de la Agencia Española de Protección de Datos.', 'dsmk-legal' ), $site, $domain ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>

<h2><?php esc_html_e( '1. ¿Qué son las cookies y para qué sirven?', 'dsmk-legal' ); ?></h2>
<p><?php esc_html_e( 'Una cookie es un pequeño archivo de texto que una página web descarga en tu ordenador, móvil o tableta cuando la visitas. Permite a la web almacenar y recuperar información sobre tu navegación o tu equipo y, según la información que contenga y cómo se utilice, puede servir para reconocerte como usuario.', 'dsmk-legal' ); ?></p>
<p><?php esc_html_e( 'Las cookies cumplen funciones muy diversas: permiten que la web funcione correctamente (por ejemplo, mantener la sesión o el carrito de la compra), recordar tus preferencias, obtener estadísticas sobre cómo se usa la web para mejorarla o mostrarte publicidad adaptada a tus intereses.', 'dsmk-legal' ); ?></p>
<p><?php esc_html_e( 'Esta política se aplica también a tecnologías similares que almacenan o leen información en tu dispositivo, como el almacenamiento local del navegador (localStorage), los píxeles o las balizas web.', 'dsmk-legal' ); ?></p>

<h2><?php esc_html_e( '2. Responsable', 'dsmk-legal' ); ?></h2>
<p><?php printf( esc_html__( 'El responsable de las cookies propias de esta web es %1$s, con NIF %2$s, domicilio en %3$s y email de contacto %4$s. Las cookies de terceros son responsabilidad de las empresas indicadas en el apartado 4.', 'dsmk-legal' ), self::v( 'titular_nombre', __( 'titular', 'dsmk-legal' ) ), self::v( 'titular_nif', 'NIF' ), self::v( 'titular_domicilio', __( 'domicilio', 'dsmk-legal' ) ), self::v( 'titular_email', 'email' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>

<h2><?php esc_html_e( '3. Tipos de cookies', 'dsmk-legal' ); ?></h2>
<h3><?php esc_html_e( 'Según la entidad que las gestiona', 'dsmk-legal' ); ?></h3>
<ul>
	<li><strong><?php esc_html_e( 'Cookies propias:', 'dsmk-legal' ); ?></strong> <?php esc_html_e( 'se envían a tu equipo desde un dominio gestionado por el titular de la web y desde el que se presta el servicio que solicitas.', 'dsmk-legal' ); ?></li>
	<li><strong><?php esc_html_e( 'Cookies de terceros:', 'dsmk-legal' ); ?></strong> <?php esc_html_e( 'se envían a tu equipo desde un dominio que no gestiona el titular de la web, sino otra entidad que trata los datos obtenidos (por ejemplo, Google o Meta).', 'dsmk-legal' ); ?></li>
</ul>
<h3><?php esc_html_e( 'Según su finalidad', 'dsmk-legal' ); ?></h3>
<ul>
	<li><strong><?php esc_html_e( 'Técnicas (necesarias):', 'dsmk-legal' ); ?></strong> <?php esc_html_e( 'permiten navegar por la web y usar sus funciones básicas: controlar el tráfico, identificar la sesión, gestionar el carrito y el pago, aplicar medidas de seguridad, recordar el idioma elegido o guardar tu decisión sobre las cookies. Están exentas de consentimiento.', 'dsmk-legal' ); ?></li>
	<li><strong><?php esc_html_e( 'De preferencias o personalización:', 'dsmk-legal' ); ?></strong> <?php esc_html_e( 'recuerdan información para que accedas al servicio con características que te diferencian de otros usuarios o habilitan funciones de terceros (chat, reservas).', 'dsmk-legal' ); ?></li>
	<li><strong><?php esc_html_e( 'De análisis o medición:', 'dsmk-legal' ); ?></strong> <?php esc_html_e( 'permiten cuantificar el número de usuarios y analizar estadísticamente cómo se usa la web, para mejorarla.', 'dsmk-legal' ); ?></li>
	<li><strong><?php esc_html_e( 'De publicidad comportamental:', 'dsmk-legal' ); ?></strong> <?php esc_html_e( 'almacenan información del comportamiento de los usuarios obtenida mediante la observación continuada de sus hábitos de navegación, lo que permite elaborar un perfil para mostrar publicidad en función del mismo y medir la eficacia de las campañas. En esta categoría se incluyen también los contenidos incrustados de terceros (vídeos, mapas, redes sociales) que instalan cookies con estos fines.', 'dsmk-legal' ); ?></li>
</ul>
<h3><?php esc_html_e( 'Según el plazo de conservación', 'dsmk-legal' ); ?></h3>
<ul>
	<li><strong><?php esc_html_e( 'De sesión:', 'dsmk-legal' ); ?></strong> <?php esc_html_e( 'se eliminan cuando cierras el navegador.', 'dsmk-legal' ); ?></li>
	<li><strong><?php esc_html_e( 'Persistentes:', 'dsmk-legal' ); ?></strong> <?php esc_html_e( 'permanecen en tu equipo durante el plazo indicado para cada una en la tabla siguiente.', 'dsmk-legal' ); ?></li>
</ul>

<h2><?php esc_html_e( '4. Cookies que utiliza esta web', 'dsmk-legal' ); ?></h2>
<p><?php printf( esc_html__( 'A continuación se detallan las cookies que utiliza %s, agrupadas por finalidad, con su nombre, titular, tipo (propia o de terceros), finalidad y plazo de conservación o caducidad. Las cookies que no son técnicas solo se instalan si das tu consentimiento.', 'dsmk-legal' ), $site ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
<?php echo self::cookie_tables(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escapado en cookie_tables(). ?>

<?php if ( $third ) : ?>
<h3><?php esc_html_e( 'Terceros y transferencias internacionales', 'dsmk-legal' ); ?></h3>
<p><?php esc_html_e( 'Estos terceros pueden instalar cookies a través de la web. Puedes consultar cómo tratan tus datos en sus políticas de privacidad:', 'dsmk-legal' ); ?></p>
<ul>
	<?php foreach ( $third as $party ) : ?>
	<li><strong><?php echo esc_html( $party['provider'] ); ?></strong> (<?php echo esc_html( implode( ', ', array_unique( $party['services'] ) ) ); ?>)<?php if ( $party['privacy'] ) : ?> — <a href="<?php echo esc_url( $party['privacy'] ); ?>" target="_blank" rel="noopener nofollow"><?php esc_html_e( 'política de privacidad', 'dsmk-legal' ); ?></a><?php endif; ?><?php if ( $party['transfer'] ) : ?>. <?php printf( esc_html__( 'Transferencia internacional: %s.', 'dsmk-legal' ), esc_html( $party['transfer'] ) ); ?><?php endif; ?></li>
	<?php endforeach; ?>
</ul>
<p><?php esc_html_e( 'Cuando un tercero trata datos fuera del Espacio Económico Europeo, la transferencia se ampara en una decisión de adecuación de la Comisión Europea (como el Marco de Privacidad de Datos UE-EE. UU. para las empresas adheridas) o en las cláusulas contractuales tipo aprobadas por la Comisión.', 'dsmk-legal' ); ?></p>
<?php endif; ?>

<h2><?php esc_html_e( '5. Cómo aceptar, rechazar o retirar tu consentimiento', 'dsmk-legal' ); ?></h2>
<p><?php esc_html_e( 'Al entrar en la web se muestra un aviso en el que puedes «Aceptar todas» las cookies, «Rechazar todas» o «Configurar» y elegir por categorías. Ambas opciones, aceptar y rechazar, se presentan con el mismo nivel de visibilidad. Mientras no decidas, solo se usan cookies técnicas.', 'dsmk-legal' ); ?></p>
<p><?php printf( esc_html__( 'Puedes cambiar o retirar tu consentimiento en cualquier momento, con la misma facilidad con la que lo diste, desde este enlace: %s, o desde el icono de cookies que aparece en la esquina inferior de la pantalla. Al retirarlo eliminamos las cookies propias de las categorías desactivadas y la página se recarga para que deje de usarse cualquier cookie que no hayas aceptado.', 'dsmk-legal' ), self::settings_link() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
<p><?php printf( esc_html__( 'Tu decisión se guarda durante %s. Pasado ese plazo, o si cambiamos las cookies que utilizamos, te volveremos a preguntar.', 'dsmk-legal' ), $duration ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
<p><?php esc_html_e( 'También puedes permitir, bloquear o eliminar las cookies instaladas en tu equipo desde la configuración de tu navegador:', 'dsmk-legal' ); ?></p>
<ul>
	<li><a href="https://support.google.com/chrome/answer/95647?hl=es" target="_blank" rel="noopener nofollow">Google Chrome</a></li>
	<li><a href="https://support.mozilla.org/es/kb/cookies-informacion-que-los-sitios-web-guardan-en-" target="_blank" rel="noopener nofollow">Mozilla Firefox</a></li>
	<li><a href="https://support.apple.com/es-es/guide/safari/sfri11471/mac" target="_blank" rel="noopener nofollow">Safari</a></li>
	<li><a href="https://support.microsoft.com/es-es/microsoft-edge/eliminar-cookies-en-microsoft-edge-63947406-40ac-c3b8-57b9-2a946a29ae09" target="_blank" rel="noopener nofollow">Microsoft Edge</a></li>
	<li><a href="https://help.opera.com/en/latest/web-preferences/#cookies" target="_blank" rel="noopener nofollow">Opera</a></li>
</ul>
<p><?php esc_html_e( 'Si bloqueas todas las cookies desde el navegador, es posible que algunas funciones de la web no estén disponibles.', 'dsmk-legal' ); ?></p>

<h2><?php esc_html_e( '6. Actualización de esta política', 'dsmk-legal' ); ?></h2>
<p><?php printf( esc_html__( 'Revisamos periódicamente las cookies de la web y actualizamos esta política cuando hay cambios. Si se incorporan cookies con nuevas finalidades, volveremos a pedirte el consentimiento. Para más información sobre el tratamiento de tus datos consulta nuestra %s.', 'dsmk-legal' ), self::link( 'privacidad', __( 'política de privacidad', 'dsmk-legal' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
<?php echo DSMKC_Documents::extra( 'cookies' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<p><em><?php printf( esc_html__( 'Última actualización: %s.', 'dsmk-legal' ), esc_html( self::updated_date() ) ); ?></em></p>
</div>
		<?php
		return (string) ob_get_clean();
	}

	/* =====================================================================
	 * Política de privacidad
	 * ===================================================================== */

	/**
	 * Definiciones de tratamientos por finalidad.
	 *
	 * @since  1.0.0
	 * @return array<string, array{0:string, 1:string, 2:string, 3:string}> clave => [título, finalidad, base jurídica, conservación].
	 */
	private static function treatments() {
		$prescription = __( 'y, después, durante los plazos de prescripción de las posibles responsabilidades legales', 'dsmk-legal' );
		return array(
			'contacto'    => array( __( 'Consultas por formulario, email o teléfono', 'dsmk-legal' ), __( 'Atender y responder a tus consultas.', 'dsmk-legal' ), __( 'Tu consentimiento (art. 6.1.a RGPD) y, si la consulta se refiere a nuestros servicios, la aplicación de medidas precontractuales a tu solicitud (art. 6.1.b RGPD).', 'dsmk-legal' ), __( 'El tiempo necesario para responderte', 'dsmk-legal' ) . ' ' . $prescription . '.' ),
			'presupuesto' => array( __( 'Solicitudes de presupuesto', 'dsmk-legal' ), __( 'Elaborar y enviarte el presupuesto solicitado y hacer su seguimiento.', 'dsmk-legal' ), __( 'Aplicación de medidas precontractuales a petición tuya (art. 6.1.b RGPD).', 'dsmk-legal' ), __( 'Durante la validez del presupuesto', 'dsmk-legal' ) . ' ' . $prescription . '.' ),
			'tienda'      => array( __( 'Clientes y pedidos de la tienda online', 'dsmk-legal' ), __( 'Gestionar tus pedidos, pagos, envíos, devoluciones, garantías y la facturación.', 'dsmk-legal' ), __( 'Ejecución del contrato de compraventa (art. 6.1.b RGPD) y cumplimiento de obligaciones legales fiscales y contables (art. 6.1.c RGPD).', 'dsmk-legal' ), __( 'Durante la relación comercial y, después, durante los plazos legales (6 años según el Código de Comercio y 4 años según la normativa tributaria).', 'dsmk-legal' ) ),
			'servicios'   => array( __( 'Clientes de servicios', 'dsmk-legal' ), __( 'Gestionar los encargos contratados, la comunicación con el cliente, la facturación y el cobro.', 'dsmk-legal' ), __( 'Ejecución del contrato (art. 6.1.b RGPD) y cumplimiento de obligaciones legales fiscales y contables (art. 6.1.c RGPD).', 'dsmk-legal' ), __( 'Durante la relación comercial y, después, durante los plazos legales (6 años según el Código de Comercio y 4 años según la normativa tributaria).', 'dsmk-legal' ) ),
			'registro'    => array( __( 'Usuarios registrados', 'dsmk-legal' ), __( 'Crear y gestionar tu cuenta de usuario y el acceso al área privada.', 'dsmk-legal' ), __( 'Ejecución de las condiciones de uso que aceptas al registrarte (art. 6.1.b RGPD).', 'dsmk-legal' ), __( 'Mientras mantengas tu cuenta activa', 'dsmk-legal' ) . ' ' . $prescription . '.' ),
			'newsletter'  => array( __( 'Newsletter y comunicaciones comerciales', 'dsmk-legal' ), __( 'Enviarte información sobre novedades, productos y servicios por email u otros medios electrónicos.', 'dsmk-legal' ), __( 'Tu consentimiento (art. 6.1.a RGPD y art. 21 LSSI). Si ya eres cliente, el interés legítimo para informarte de productos o servicios similares (art. 21.2 LSSI). Puedes darte de baja en cualquier momento desde el enlace incluido en cada comunicación.', 'dsmk-legal' ), __( 'Hasta que te des de baja o retires tu consentimiento.', 'dsmk-legal' ) ),
			'empleo'      => array( __( 'Currículums', 'dsmk-legal' ), __( 'Valorar tu candidatura en los procesos de selección actuales y futuros.', 'dsmk-legal' ), __( 'Tu consentimiento al enviarnos el currículum (art. 6.1.a RGPD).', 'dsmk-legal' ), __( 'Un máximo de un año desde su recepción, salvo que retires antes tu consentimiento.', 'dsmk-legal' ) ),
			'comentarios' => array( __( 'Comentarios en el blog', 'dsmk-legal' ), __( 'Publicar y moderar los comentarios que dejas en la web.', 'dsmk-legal' ), __( 'Tu consentimiento (art. 6.1.a RGPD).', 'dsmk-legal' ), __( 'Mientras el comentario permanezca publicado o hasta que solicites su supresión.', 'dsmk-legal' ) ),
			'reservas'    => array( __( 'Reservas y citas', 'dsmk-legal' ), __( 'Gestionar tus reservas o citas y comunicarnos contigo sobre ellas.', 'dsmk-legal' ), __( 'Ejecución del contrato o medidas precontractuales (art. 6.1.b RGPD).', 'dsmk-legal' ), __( 'Durante la gestión de la reserva', 'dsmk-legal' ) . ' ' . $prescription . '.' ),
		);
	}

	/**
	 * [dsmk_politica_privacidad]
	 *
	 * @since  1.0.0
	 * @return string HTML.
	 */
	public static function privacy_policy() {
		$s          = DSMKC_Settings::all();
		$site       = esc_html( self::site_name() );
		$treatments = self::treatments();
		$selected   = (array) $s['finalidades'];
		// Los documentos de contratación activos implican sus tratamientos.
		if ( ! empty( $s['doc_condiciones'] ) && ! in_array( 'tienda', $selected, true ) ) {
			$selected[] = 'tienda';
		}
		if ( ! empty( $s['doc_terminos'] ) && ! in_array( 'servicios', $selected, true ) ) {
			$selected[] = 'servicios';
		}
		$third      = self::third_parties();
		$transfers  = array_filter(
			$third,
			static function ( $party ) {
				return '' !== $party['transfer'];
			}
		);

		ob_start();
		?>
<div class="dsmkc-legal dsmkc-legal--privacidad">
<p><?php printf( esc_html__( 'En esta política te informamos de cómo tratamos los datos personales que nos facilitas a través de %s, conforme al Reglamento (UE) 2016/679 (RGPD) y a la Ley Orgánica 3/2018, de Protección de Datos Personales y garantía de los derechos digitales (LOPDGDD).', 'dsmk-legal' ), $site ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>

<h2><?php esc_html_e( '1. Responsable del tratamiento', 'dsmk-legal' ); ?></h2>
<ul>
	<li><strong><?php esc_html_e( 'Titular:', 'dsmk-legal' ); ?></strong> <?php echo self::v( 'titular_nombre', __( 'titular', 'dsmk-legal' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></li>
	<li><strong><?php esc_html_e( 'NIF:', 'dsmk-legal' ); ?></strong> <?php echo self::v( 'titular_nif', 'NIF' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></li>
	<li><strong><?php esc_html_e( 'Domicilio:', 'dsmk-legal' ); ?></strong> <?php echo self::v( 'titular_domicilio', __( 'domicilio', 'dsmk-legal' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></li>
	<li><strong><?php esc_html_e( 'Email:', 'dsmk-legal' ); ?></strong> <?php echo self::v( 'titular_email', 'email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></li>
	<?php if ( '' !== trim( $s['titular_telefono'] ) ) : ?><li><strong><?php esc_html_e( 'Teléfono:', 'dsmk-legal' ); ?></strong> <?php echo esc_html( $s['titular_telefono'] ); ?></li><?php endif; ?>
	<?php if ( '' !== trim( $s['titular_dpo'] ) ) : ?><li><strong><?php esc_html_e( 'Delegado de Protección de Datos:', 'dsmk-legal' ); ?></strong> <?php echo esc_html( $s['titular_dpo'] ); ?></li><?php endif; ?>
</ul>

<h2><?php esc_html_e( '2. Qué datos tratamos, con qué finalidad, con qué base jurídica y durante cuánto tiempo', 'dsmk-legal' ); ?></h2>
<?php foreach ( $selected as $key ) : ?>
	<?php if ( ! isset( $treatments[ $key ] ) ) { continue; } ?>
	<h3><?php echo esc_html( $treatments[ $key ][0] ); ?></h3>
	<ul>
		<li><strong><?php esc_html_e( 'Finalidad:', 'dsmk-legal' ); ?></strong> <?php echo esc_html( $treatments[ $key ][1] ); ?></li>
		<li><strong><?php esc_html_e( 'Base jurídica:', 'dsmk-legal' ); ?></strong> <?php echo esc_html( $treatments[ $key ][2] ); ?></li>
		<li><strong><?php esc_html_e( 'Conservación:', 'dsmk-legal' ); ?></strong> <?php echo esc_html( $treatments[ $key ][3] ); ?></li>
	</ul>
<?php endforeach; ?>
<h3><?php esc_html_e( 'Navegación y cookies', 'dsmk-legal' ); ?></h3>
<ul>
	<li><strong><?php esc_html_e( 'Finalidad:', 'dsmk-legal' ); ?></strong> <?php esc_html_e( 'Garantizar el funcionamiento y la seguridad de la web y, solo si lo aceptas, obtener estadísticas de uso o mostrarte publicidad personalizada.', 'dsmk-legal' ); ?></li>
	<li><strong><?php esc_html_e( 'Base jurídica:', 'dsmk-legal' ); ?></strong> <?php esc_html_e( 'El interés legítimo en la seguridad de la web (art. 6.1.f RGPD) para los registros técnicos y las cookies necesarias, y tu consentimiento (art. 6.1.a RGPD) para el resto de cookies.', 'dsmk-legal' ); ?></li>
	<li><strong><?php esc_html_e( 'Conservación:', 'dsmk-legal' ); ?></strong> <?php printf( esc_html__( 'La indicada para cada cookie en la %s.', 'dsmk-legal' ), self::link( 'cookies', __( 'política de cookies', 'dsmk-legal' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></li>
</ul>
<p><?php esc_html_e( 'Los campos marcados como obligatorios en los formularios son necesarios para atender tu solicitud; si no los facilitas, no podremos hacerlo. Nos garantizas que los datos que nos facilitas son ciertos y que, si son de terceros, cuentas con su autorización para ello. No tomamos decisiones automatizadas que produzcan efectos jurídicos sobre ti.', 'dsmk-legal' ); ?></p>

<h2><?php esc_html_e( '3. Destinatarios', 'dsmk-legal' ); ?></h2>
<p><?php esc_html_e( 'No cedemos tus datos a terceros salvo obligación legal (por ejemplo, a la Agencia Tributaria, a bancos para gestionar cobros o a jueces y tribunales). Para prestar nuestros servicios contamos con proveedores que tratan datos por nuestra cuenta como encargados del tratamiento, con los que hemos firmado el correspondiente contrato:', 'dsmk-legal' ); ?></p>
<ul>
	<li><?php printf( esc_html__( 'Alojamiento web: %s.', 'dsmk-legal' ), '' !== trim( $s['hosting_proveedor'] ) ? esc_html( $s['hosting_proveedor'] ) : esc_html__( 'proveedor de alojamiento web', 'dsmk-legal' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></li>
	<?php foreach ( $third as $party ) : ?>
	<li><?php echo esc_html( $party['provider'] . ' (' . implode( ', ', array_unique( $party['services'] ) ) . ')' ); ?></li>
	<?php endforeach; ?>
	<?php if ( in_array( 'tienda', $selected, true ) ) : ?><li><?php esc_html_e( 'Empresas de transporte y mensajería, para la entrega de los pedidos, y pasarelas de pago, para el cobro.', 'dsmk-legal' ); ?></li><?php endif; ?>
	<li><?php esc_html_e( 'Asesoría fiscal y contable, cuando sea necesario para cumplir nuestras obligaciones legales.', 'dsmk-legal' ); ?></li>
</ul>

<h2><?php esc_html_e( '4. Transferencias internacionales', 'dsmk-legal' ); ?></h2>
<?php if ( $transfers ) : ?>
<p><?php esc_html_e( 'Algunos de los proveedores indicados pueden tratar datos fuera del Espacio Económico Europeo:', 'dsmk-legal' ); ?></p>
<ul>
	<?php foreach ( $transfers as $party ) : ?>
	<li><?php echo esc_html( $party['provider'] . ': ' . $party['transfer'] . '.' ); ?></li>
	<?php endforeach; ?>
</ul>
<p><?php esc_html_e( 'Estas transferencias se amparan en una decisión de adecuación de la Comisión Europea (como el Marco de Privacidad de Datos UE-EE. UU. para las empresas adheridas) o en las cláusulas contractuales tipo aprobadas por la Comisión Europea.', 'dsmk-legal' ); ?></p>
<?php else : ?>
<p><?php esc_html_e( 'No realizamos transferencias internacionales de datos fuera del Espacio Económico Europeo.', 'dsmk-legal' ); ?></p>
<?php endif; ?>

<h2><?php esc_html_e( '5. Tus derechos', 'dsmk-legal' ); ?></h2>
<p><?php printf( esc_html__( 'Puedes ejercer en cualquier momento tus derechos de acceso, rectificación, supresión, oposición, limitación del tratamiento y portabilidad de tus datos, así como retirar el consentimiento prestado, escribiendo a %1$s o a %2$s, indicando el derecho que ejerces. Podremos pedirte que acredites tu identidad si tenemos dudas razonables sobre ella. Retirar el consentimiento no afecta a la licitud de los tratamientos anteriores.', 'dsmk-legal' ), self::v( 'titular_email', 'email' ), self::v( 'titular_domicilio', __( 'domicilio', 'dsmk-legal' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
<p><?php echo wp_kses_post( __( 'Si consideras que no hemos tratado tus datos correctamente, puedes presentar una reclamación ante la Agencia Española de Protección de Datos (C/ Jorge Juan, 6, 28001 Madrid — <a href="https://www.aepd.es" target="_blank" rel="noopener">www.aepd.es</a>).', 'dsmk-legal' ) ); ?></p>

<h2><?php esc_html_e( '6. Menores de edad', 'dsmk-legal' ); ?></h2>
<p><?php esc_html_e( 'Los menores de 14 años no pueden facilitarnos datos personales sin el consentimiento de sus padres o tutores (art. 7 LOPDGDD).', 'dsmk-legal' ); ?></p>

<h2><?php esc_html_e( '7. Seguridad', 'dsmk-legal' ); ?></h2>
<p><?php esc_html_e( 'Aplicamos las medidas técnicas y organizativas adecuadas para garantizar la confidencialidad, integridad y disponibilidad de tus datos y evitar su pérdida, alteración o acceso no autorizado, teniendo en cuenta el estado de la técnica y los riesgos del tratamiento.', 'dsmk-legal' ); ?></p>

<h2><?php esc_html_e( '8. Cambios en esta política', 'dsmk-legal' ); ?></h2>
<?php echo DSMKC_Documents::extra( 'privacidad' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<p><?php printf( esc_html__( 'Podemos actualizar esta política para adaptarla a cambios legales o en nuestros tratamientos. Te recomendamos revisarla periódicamente. Última actualización: %s.', 'dsmk-legal' ), esc_html( self::updated_date() ) ); ?></p>
</div>
		<?php
		return (string) ob_get_clean();
	}

	/* =====================================================================
	 * Aviso legal
	 * ===================================================================== */

	/**
	 * [dsmk_aviso_legal]
	 *
	 * @since  1.0.0
	 * @return string HTML.
	 */
	public static function legal_notice() {
		$s      = DSMKC_Settings::all();
		$site   = esc_html( self::site_name() );
		$domain = esc_html( self::domain() );
		$juris  = '' !== trim( $s['jurisdiccion'] ) ? esc_html( $s['jurisdiccion'] ) : esc_html__( 'del domicilio del titular', 'dsmk-legal' );

		ob_start();
		?>
<div class="dsmkc-legal dsmkc-legal--aviso">
<h2><?php esc_html_e( '1. Datos identificativos', 'dsmk-legal' ); ?></h2>
<p><?php esc_html_e( 'En cumplimiento del artículo 10 de la Ley 34/2002, de servicios de la sociedad de la información y de comercio electrónico (LSSI), se informa de los datos del titular de esta web:', 'dsmk-legal' ); ?></p>
<ul>
	<li><strong><?php esc_html_e( 'Titular:', 'dsmk-legal' ); ?></strong> <?php echo self::v( 'titular_nombre', __( 'titular', 'dsmk-legal' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></li>
	<li><strong><?php esc_html_e( 'NIF:', 'dsmk-legal' ); ?></strong> <?php echo self::v( 'titular_nif', 'NIF' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></li>
	<li><strong><?php esc_html_e( 'Domicilio:', 'dsmk-legal' ); ?></strong> <?php echo self::v( 'titular_domicilio', __( 'domicilio', 'dsmk-legal' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></li>
	<li><strong><?php esc_html_e( 'Email:', 'dsmk-legal' ); ?></strong> <?php echo self::v( 'titular_email', 'email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></li>
	<?php if ( '' !== trim( $s['titular_telefono'] ) ) : ?><li><strong><?php esc_html_e( 'Teléfono:', 'dsmk-legal' ); ?></strong> <?php echo esc_html( $s['titular_telefono'] ); ?></li><?php endif; ?>
	<?php if ( '' !== trim( $s['titular_registro'] ) ) : ?><li><strong><?php esc_html_e( 'Datos registrales:', 'dsmk-legal' ); ?></strong> <?php echo esc_html( $s['titular_registro'] ); ?></li><?php elseif ( in_array( $s['titular_tipo'], array( 'sociedad', 'asociacion' ), true ) ) : ?><li><strong><?php esc_html_e( 'Datos registrales:', 'dsmk-legal' ); ?></strong> <?php echo self::v( 'titular_registro', __( 'datos registrales', 'dsmk-legal' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></li><?php endif; ?>
	<?php if ( '' !== trim( $s['titular_colegio'] ) ) : ?><li><strong><?php esc_html_e( 'Profesión regulada:', 'dsmk-legal' ); ?></strong> <?php echo esc_html( $s['titular_colegio'] ); ?></li><?php endif; ?>
</ul>

<h2><?php esc_html_e( '2. Objeto', 'dsmk-legal' ); ?></h2>
<p><?php printf( esc_html__( 'Este aviso legal regula el acceso y el uso de la web %1$s (%2$s)%3$s. Acceder a la web te atribuye la condición de usuario e implica la aceptación de estas condiciones.', 'dsmk-legal' ), $site, $domain, '' !== trim( $s['titular_actividad'] ) ? ', ' . esc_html__( 'cuyo objeto es', 'dsmk-legal' ) . ' ' . esc_html( $s['titular_actividad'] ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>

<h2><?php esc_html_e( '3. Condiciones de uso', 'dsmk-legal' ); ?></h2>
<p><?php esc_html_e( 'El usuario se compromete a hacer un uso adecuado y lícito de la web y de sus contenidos, de conformidad con la legislación aplicable, este aviso legal, la moral y el orden público. En particular, se compromete a no utilizar la web para actividades ilícitas o que lesionen derechos de terceros, a no introducir virus o sistemas que puedan dañar los sistemas del titular o de terceros, y a no intentar acceder a áreas restringidas sin autorización.', 'dsmk-legal' ); ?></p>

<h2><?php esc_html_e( '4. Propiedad intelectual e industrial', 'dsmk-legal' ); ?></h2>
<p><?php esc_html_e( 'Todos los contenidos de la web (textos, fotografías, gráficos, imágenes, iconos, tecnología, software, diseño, logotipos y marcas) son titularidad del titular de la web o de terceros que han autorizado su uso, y están protegidos por la normativa de propiedad intelectual e industrial. Queda prohibida su reproducción, distribución, comunicación pública o transformación sin autorización expresa, salvo para uso personal y privado.', 'dsmk-legal' ); ?></p>

<h2><?php esc_html_e( '5. Responsabilidad', 'dsmk-legal' ); ?></h2>
<p><?php esc_html_e( 'El titular trabaja para que la web esté disponible y sus contenidos sean correctos y actualizados, pero no garantiza la ausencia de interrupciones o errores ni se hace responsable de los daños que pudieran derivarse de ellos, salvo en los casos en que la ley lo establezca. Tampoco se responsabiliza del uso que los usuarios hagan de la web en contra de este aviso legal.', 'dsmk-legal' ); ?></p>

<h2><?php esc_html_e( '6. Enlaces', 'dsmk-legal' ); ?></h2>
<p><?php esc_html_e( 'La web puede contener enlaces a sitios de terceros. El titular no controla esos sitios ni es responsable de sus contenidos; si tuviera conocimiento efectivo de que un enlace remite a contenidos ilícitos, lo retirará con la mayor brevedad.', 'dsmk-legal' ); ?></p>

<h2><?php esc_html_e( '7. Protección de datos y cookies', 'dsmk-legal' ); ?></h2>
<p><?php printf( esc_html__( 'El tratamiento de tus datos personales se rige por nuestra %1$s, y el uso de cookies por nuestra %2$s.', 'dsmk-legal' ), self::link( 'privacidad', __( 'política de privacidad', 'dsmk-legal' ) ), self::link( 'cookies', __( 'política de cookies', 'dsmk-legal' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>

<h2><?php esc_html_e( '8. Legislación aplicable y jurisdicción', 'dsmk-legal' ); ?></h2>
<p><?php printf( esc_html__( 'Estas condiciones se rigen por la legislación española. Para la resolución de cualquier controversia, si el usuario es consumidor serán competentes los juzgados y tribunales de su domicilio; en el resto de casos, las partes se someten a los juzgados y tribunales de %s.', 'dsmk-legal' ), $juris ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
<?php echo DSMKC_Documents::extra( 'aviso' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</div>
		<?php
		return (string) ob_get_clean();
	}

	/* =====================================================================
	 * Páginas
	 * ===================================================================== */

	/**
	 * Crea las páginas legales que falten (compatibilidad con 1.x).
	 *
	 * @since  1.0.0
	 * @return array<string, int>
	 */
	public static function create_pages() {
		return DSMKC_Documents::create_pages();
	}
}
