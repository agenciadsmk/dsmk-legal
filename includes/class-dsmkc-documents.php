<?php
/**
 * DSMK Legal — Registro de documentos legales, páginas y textos de contratación.
 *
 * @package   DSMK_Legal
 * @file      includes/class-dsmkc-documents.php
 * @since     2.0.0
 * @author    DSMK Agencia Digital <hola@dsmk.es>
 * @license   GPL-2.0-or-later
 *
 * Responsabilidad:
 *   - Registro único de documentos del hub legal (filtrable con
 *     `dsmkc_legal_documents` para que otros plugins de DSMK, como el de
 *     desistimiento de WooCommerce, añadan los suyos).
 *   - Creación automática de las páginas que falten, con su shortcode, al
 *     guardar cualquier formulario de configuración.
 *   - Textos nuevos en 2.0:
 *       [dsmk_terminos_servicio]  Términos y condiciones de servicios.
 *       [dsmk_condiciones_venta]  Condiciones generales de venta (tienda online).
 *       [dsmk_desistimiento]      Información y formulario modelo de desistimiento.
 *       [dsmk_desistimiento_formulario] Hueco para la función de desistimiento
 *                                 online (Directiva UE 2023/2673) que aporta el
 *                                 plugin de desistimiento mediante el filtro
 *                                 `dsmkc_withdrawal_form`.
 *   - Cláusulas adicionales por documento (texto libre al final).
 *
 * Normativa de referencia de los textos: RDL 1/2007 (TRLGDCU) arts. 60, 97,
 * 102-108 y 114-127 (garantía de 3 años tras RDL 7/2021), anexos A y B;
 * Ley 7/1998 de condiciones generales; LSSI arts. 10, 23-28; Ley 3/2004
 * (morosidad); TRLPI (propiedad intelectual); RGPD art. 28.
 *
 * Seguridad: todo dato del formulario se escapa al componer el HTML; las
 * cláusulas adicionales pasan por wp_kses_post().
 */

defined( 'ABSPATH' ) || exit;

/**
 * Documentos del hub legal.
 *
 * @since 2.0.0
 */
final class DSMKC_Documents {

	/** @var array<string, int> Páginas creadas en la petición actual (para avisos en el panel). */
	public static $last_created = array();

	/** @var array<string, int> Documentos no creados porque ya existe una página parecida (tipo => id sugerido). */
	public static $last_skipped = array();

	/** @var array<string, string> Patrones para reconocer páginas legales que ya tenga la web. */
	const SIMILAR = array(
		'aviso'         => '/aviso[\s\-]*legal|legal[\s\-]*notice|informaci[oó]n[\s\-]*legal/iu',
		'privacidad'    => '/privacidad|privacy|protecci[oó]n[\s\-]*de[\s\-]*datos/iu',
		'cookies'       => '/cookies/iu',
		'terminos'      => '/t[eé]rminos|condiciones[\s\-]*de[\s\-]*(uso|servicio|contrataci[oó]n[\s\-]*de[\s\-]*servicios)/iu',
		'condiciones'   => '/condiciones[\s\-]*(generales[\s\-]*)?de[\s\-]*(venta|compra|contrataci[oó]n)|t[eé]rminos[\s\-]*y[\s\-]*condiciones[\s\-]*de[\s\-]*venta/iu',
		'desistimiento' => '/desistimiento|devoluciones/iu',
	);

	/**
	 * Registro de documentos.
	 *
	 * Claves de cada documento:
	 *   title     Título de la página que se crea.
	 *   shortcode Shortcode sin corchetes.
	 *   setting   Ajuste que guarda el ID de la página.
	 *   toggle    Ajuste booleano que activa el documento ('' = siempre activo).
	 *   footer    Texto del enlace en el pie.
	 *   external  true si el contenido lo gestiona otro plugin (no se crea página).
	 *
	 * @since  2.0.0
	 * @return array<string, array>
	 */
	public static function registry() {
		$docs = array(
			'aviso'         => array( 'title' => __( 'Aviso legal', 'dsmk-legal' ), 'shortcode' => 'dsmk_aviso_legal', 'setting' => 'pagina_aviso', 'toggle' => 'doc_aviso', 'footer' => __( 'Aviso legal', 'dsmk-legal' ) ),
			'privacidad'    => array( 'title' => __( 'Política de privacidad', 'dsmk-legal' ), 'shortcode' => 'dsmk_politica_privacidad', 'setting' => 'pagina_privacidad', 'toggle' => 'doc_privacidad', 'footer' => __( 'Política de privacidad', 'dsmk-legal' ) ),
			'cookies'       => array( 'title' => __( 'Política de cookies', 'dsmk-legal' ), 'shortcode' => 'dsmk_politica_cookies', 'setting' => 'pagina_cookies', 'toggle' => 'doc_cookies', 'footer' => __( 'Política de cookies', 'dsmk-legal' ) ),
			'terminos'      => array( 'title' => __( 'Términos y condiciones del servicio', 'dsmk-legal' ), 'shortcode' => 'dsmk_terminos_servicio', 'setting' => 'pagina_terminos', 'toggle' => 'doc_terminos', 'footer' => __( 'Términos y condiciones', 'dsmk-legal' ) ),
			'condiciones'   => array( 'title' => __( 'Condiciones generales de venta', 'dsmk-legal' ), 'shortcode' => 'dsmk_condiciones_venta', 'setting' => 'pagina_condiciones', 'toggle' => 'doc_condiciones', 'footer' => __( 'Condiciones de venta', 'dsmk-legal' ) ),
			'desistimiento' => array( 'title' => __( 'Derecho de desistimiento', 'dsmk-legal' ), 'shortcode' => 'dsmk_desistimiento', 'setting' => 'pagina_desistimiento', 'toggle' => 'doc_desistimiento', 'footer' => __( 'Desistimiento', 'dsmk-legal' ), 'external' => 'externo' === DSMKC_Settings::get( 'desist_modo' ) ),
		);

		/**
		 * Permite a otros plugins registrar documentos en el hub legal.
		 *
		 * Ejemplo (plugin de desistimiento):
		 *   add_filter( 'dsmkc_legal_documents', function ( $docs ) {
		 *       $docs['desistimiento']['external'] = true; // Su página la crea ese plugin.
		 *       return $docs;
		 *   } );
		 *
		 * @since 2.0.0
		 * @param array $docs Documentos.
		 */
		return apply_filters( 'dsmkc_legal_documents', $docs );
	}

	/**
	 * Un documento del registro.
	 *
	 * @since  2.0.0
	 * @param  string $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$docs = self::registry();
		return isset( $docs[ $id ] ) ? $docs[ $id ] : null;
	}

	/**
	 * Documentos activados en los ajustes.
	 *
	 * @since  2.0.0
	 * @return array<string, array>
	 */
	public static function enabled() {
		return array_filter(
			self::registry(),
			static function ( $doc ) {
				return empty( $doc['toggle'] ) || (bool) DSMKC_Settings::get( $doc['toggle'] );
			}
		);
	}

	/**
	 * Registra shortcodes y hooks.
	 *
	 * @since  2.0.0
	 * @return void
	 */
	public function hooks() {
		add_shortcode( 'dsmk_terminos_servicio', array( __CLASS__, 'terms' ) );
		add_shortcode( 'dsmk_condiciones_venta', array( __CLASS__, 'conditions' ) );
		add_shortcode( 'dsmk_desistimiento', array( __CLASS__, 'withdrawal' ) );
		add_shortcode( 'dsmk_desistimiento_formulario', array( __CLASS__, 'withdrawal_form' ) );
		add_action( 'dsmkc_settings_saved', array( __CLASS__, 'maybe_create_pages' ), 20 );
	}

	/* =====================================================================
	 * Páginas
	 * ===================================================================== */

	/**
	 * Tras guardar cualquier formulario: crea las páginas que falten si está
	 * activada la opción y hay titular.
	 *
	 * @since  2.0.0
	 * @return array<string, int> Páginas creadas.
	 */
	public static function maybe_create_pages() {
		if ( ! DSMKC_Settings::get( 'paginas_auto' ) || '' === trim( (string) DSMKC_Settings::get( 'titular_nombre' ) ) ) {
			return array();
		}
		return self::create_pages();
	}

	/**
	 * Crea las páginas de los documentos activos que no tengan página asignada.
	 *
	 * No sobrescribe páginas existentes ni crea las de documentos gestionados
	 * por otro plugin (external). Desde 2.0.1 solo se llama desde un botón del
	 * panel (o si el administrador activa expresamente la creación automática).
	 *
	 * @since  2.0.0
	 * @since  2.0.1 Parámetros $only y $skip_suggested.
	 * @param  string|null $only           Crear solo este documento (null = todos los que falten).
	 * @param  bool        $skip_suggested true = no crear si la web ya tiene una página parecida
	 *                                     (se informa en self::$last_skipped para asignarla a mano).
	 * @return array<string, int> tipo => id de las páginas CREADAS en esta llamada.
	 */
	public static function create_pages( $only = null, $skip_suggested = false ) {
		static $running = false;
		if ( $running ) {
			return array(); // Evita recursión: guardar el ID dispara dsmkc_settings_saved.
		}
		$running = true;
		$created = array();

		foreach ( self::enabled() as $type => $doc ) {
			if ( null !== $only && $only !== $type ) {
				continue;
			}
			if ( ! empty( $doc['external'] ) || empty( $doc['setting'] ) ) {
				continue;
			}
			if ( self::assigned_page( $type ) ) {
				continue;
			}
			if ( $skip_suggested && self::suggest_existing( $type ) ) {
				self::$last_skipped[ $type ] = self::suggest_existing( $type );
				continue;
			}
			$id = wp_insert_post(
				array(
					'post_type'      => 'page',
					'post_status'    => 'publish',
					'post_title'     => $doc['title'],
					'post_content'   => "<!-- wp:shortcode -->\n[" . $doc['shortcode'] . "]\n<!-- /wp:shortcode -->",
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
				),
				true
			);
			if ( is_wp_error( $id ) || ! $id ) {
				continue;
			}
			DSMKC_Settings::save( array( $doc['setting'] => $id ), array( $doc['setting'] ) );
			$created[ $type ] = (int) $id;
		}

		self::sync_core_pages();
		$running            = false;
		self::$last_created = array_merge( self::$last_created, $created );
		return $created;
	}

	/**
	 * ID de la página asignada a un documento si existe (publicada, borrador o privada).
	 *
	 * @since  2.0.1
	 * @param  string $type Documento.
	 * @return int 0 si no hay.
	 */
	public static function assigned_page( $type ) {
		$doc = self::get( $type );
		if ( ! $doc || empty( $doc['setting'] ) ) {
			return 0;
		}
		$id = (int) DSMKC_Settings::get( $doc['setting'], 0 );
		return ( $id && in_array( get_post_status( $id ), array( 'publish', 'draft', 'private', 'pending' ), true ) ) ? $id : 0;
	}

	/**
	 * Busca una página existente que parezca ser este documento (para no duplicar).
	 *
	 * Excluye las páginas ya asignadas a otro documento del hub.
	 *
	 * @since  2.0.1
	 * @param  string $type Documento.
	 * @return int ID de la página sugerida o 0.
	 */
	public static function suggest_existing( $type ) {
		if ( ! isset( self::SIMILAR[ $type ] ) ) {
			return 0;
		}
		$taken = array();
		foreach ( self::registry() as $other => $doc ) {
			if ( $other !== $type && ! empty( $doc['setting'] ) ) {
				$taken[] = (int) DSMKC_Settings::get( $doc['setting'], 0 );
			}
		}
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 100,
				'no_found_rows'  => true,
			)
		);
		foreach ( $pages as $page ) {
			if ( in_array( (int) $page->ID, $taken, true ) ) {
				continue;
			}
			// «Política de cookies» contiene «cookies» pero «condiciones» no debe casar con términos de servicio y viceversa.
			if ( 'terminos' === $type && preg_match( self::SIMILAR['condiciones'], $page->post_title ) ) {
				continue;
			}
			if ( preg_match( self::SIMILAR[ $type ], $page->post_title . ' ' . str_replace( '-', ' ', (string) $page->post_name ) ) ) {
				return (int) $page->ID;
			}
		}
		return 0;
	}

	/**
	 * Asigna una página existente a un documento.
	 *
	 * Si $replace es true, el contenido de la página se sustituye por el
	 * shortcode del documento. Se guarda copia del contenido anterior en
	 * metadatos (_dsmkc_previous_content) y WordPress conserva la revisión.
	 * En páginas de Elementor se desactiva el modo constructor solo en esa
	 * página (se guarda el valor anterior para poder restaurarlo).
	 *
	 * @since  2.0.1
	 * @param  string $type    Documento.
	 * @param  int    $page_id Página.
	 * @param  bool   $replace Sustituir el contenido por el shortcode.
	 * @return bool
	 */
	public static function assign_page( $type, $page_id, $replace = false ) {
		$doc     = self::get( $type );
		$page_id = absint( $page_id );
		if ( ! $doc || empty( $doc['setting'] ) || ! $page_id || 'page' !== get_post_type( $page_id ) ) {
			return false;
		}
		if ( $replace && ! self::page_has_shortcode( $page_id, $doc['shortcode'] ) ) {
			$post = get_post( $page_id );
			update_post_meta( $page_id, '_dsmkc_previous_content', $post ? $post->post_content : '' );
			$mode = get_post_meta( $page_id, '_elementor_edit_mode', true );
			if ( 'builder' === $mode ) {
				update_post_meta( $page_id, '_dsmkc_previous_elementor_edit_mode', $mode );
				delete_post_meta( $page_id, '_elementor_edit_mode' );
			}
			wp_update_post(
				array(
					'ID'           => $page_id,
					'post_content' => "<!-- wp:shortcode -->\n[" . $doc['shortcode'] . "]\n<!-- /wp:shortcode -->",
				)
			);
		}
		DSMKC_Settings::save( array( $doc['setting'] => $page_id ), array( $doc['setting'] ) );
		self::sync_core_pages();
		return true;
	}

	/**
	 * ¿Muestra la página el shortcode del documento? (contenido o datos de Elementor).
	 *
	 * @since  2.0.1
	 * @param  int    $page_id   Página.
	 * @param  string $shortcode Shortcode sin corchetes.
	 * @return bool
	 */
	public static function page_has_shortcode( $page_id, $shortcode ) {
		$content = (string) get_post_field( 'post_content', $page_id );
		if ( 'builder' === get_post_meta( $page_id, '_elementor_edit_mode', true ) ) {
			$content = (string) get_post_meta( $page_id, '_elementor_data', true ); // Elementor muestra esto, no post_content.
		}
		return false !== strpos( $content, '[' . $shortcode );
	}

	/**
	 * Asigna las páginas a WordPress y WooCommerce (privacidad, términos).
	 *
	 * @since  2.0.0
	 * @return void
	 */
	public static function sync_core_pages() {
		$privacy = (int) DSMKC_Settings::get( 'pagina_privacidad', 0 );
		if ( $privacy && DSMKC_Settings::get( 'doc_privacidad' ) ) {
			update_option( 'wp_page_for_privacy_policy', $privacy );
		}
		$terms = (int) DSMKC_Settings::get( 'pagina_condiciones', 0 );
		if ( $terms && DSMKC_Settings::get( 'doc_condiciones' ) && class_exists( 'WooCommerce' ) ) {
			// WooCommerce muestra la casilla «He leído y acepto los términos» en el checkout.
			update_option( 'woocommerce_terms_page_id', $terms );
		}
	}

	/* =====================================================================
	 * Utilidades de texto
	 * ===================================================================== */

	/**
	 * Valor escapado o marcador visible de dato pendiente.
	 *
	 * @since  2.0.0
	 * @param  string $key      Ajuste.
	 * @param  string $label    Etiqueta del marcador.
	 * @param  string $fallback Ajuste alternativo si el primero está vacío.
	 * @return string HTML.
	 */
	public static function v( $key, $label, $fallback = '' ) {
		$value = trim( (string) DSMKC_Settings::get( $key, '' ) );
		if ( '' === $value && '' !== $fallback ) {
			$value = trim( (string) DSMKC_Settings::get( $fallback, '' ) );
		}
		if ( '' === $value ) {
			/* translators: %s: dato pendiente. */
			return '<mark class="dsmkc-pending">' . esc_html( sprintf( __( '[pendiente: %s]', 'dsmk-legal' ), $label ) ) . '</mark>';
		}
		return esc_html( $value );
	}

	/**
	 * Texto multilínea del formulario convertido en párrafos escapados.
	 *
	 * @since  2.0.0
	 * @param  string $key Ajuste.
	 * @return string HTML ('' si está vacío).
	 */
	private static function paragraphs( $key ) {
		$value = trim( (string) DSMKC_Settings::get( $key, '' ) );
		if ( '' === $value ) {
			return '';
		}
		$out = '';
		foreach ( preg_split( "/\n\s*\n/", $value ) as $block ) {
			$out .= '<p>' . nl2br( esc_html( trim( $block ) ) ) . '</p>';
		}
		return $out;
	}

	/**
	 * Cláusulas adicionales del documento.
	 *
	 * @since  2.0.0
	 * @param  string $doc Id del documento.
	 * @return string HTML.
	 */
	public static function extra( $doc ) {
		$value = trim( (string) DSMKC_Settings::get( 'extra_' . $doc, '' ) );
		if ( '' === $value ) {
			return '';
		}
		return '<h2>' . esc_html__( 'Otras condiciones', 'dsmk-legal' ) . '</h2>' . wpautop( wp_kses_post( $value ) );
	}

	/**
	 * Enlace a la página de un documento (o su nombre si no hay página).
	 *
	 * @since  2.0.0
	 * @param  string $doc   Id.
	 * @param  string $label Texto.
	 * @return string HTML.
	 */
	public static function link( $doc, $label ) {
		$url = DSMKC_Legal::page_url( $doc );
		return $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>' : esc_html( $label );
	}

	/**
	 * Bloque con los datos del titular.
	 *
	 * @since  2.0.0
	 * @return string HTML.
	 */
	private static function identity_list() {
		$s    = DSMKC_Settings::all();
		$html = '<ul>';
		$html .= '<li><strong>' . esc_html__( 'Titular:', 'dsmk-legal' ) . '</strong> ' . self::v( 'titular_nombre', __( 'titular', 'dsmk-legal' ) ) . '</li>';
		$html .= '<li><strong>' . esc_html__( 'NIF:', 'dsmk-legal' ) . '</strong> ' . self::v( 'titular_nif', 'NIF' ) . '</li>';
		$html .= '<li><strong>' . esc_html__( 'Domicilio:', 'dsmk-legal' ) . '</strong> ' . self::v( 'titular_domicilio', __( 'domicilio', 'dsmk-legal' ) ) . '</li>';
		$html .= '<li><strong>' . esc_html__( 'Email:', 'dsmk-legal' ) . '</strong> ' . self::v( 'titular_email', 'email' ) . '</li>';
		if ( '' !== trim( $s['titular_telefono'] ) ) {
			$html .= '<li><strong>' . esc_html__( 'Teléfono:', 'dsmk-legal' ) . '</strong> ' . esc_html( $s['titular_telefono'] ) . '</li>';
		}
		if ( '' !== trim( $s['titular_registro'] ) ) {
			$html .= '<li><strong>' . esc_html__( 'Datos registrales:', 'dsmk-legal' ) . '</strong> ' . esc_html( $s['titular_registro'] ) . '</li>';
		}
		return $html . '</ul>';
	}

	/**
	 * Fecha de actualización.
	 *
	 * @since  2.0.0
	 * @return string HTML.
	 */
	private static function updated() {
		$data = DSMKC_Settings::data();
		$ts   = max( (int) $data['updated'], (int) get_option( 'dsmkc_settings_updated', 0 ) );
		/* translators: %s: fecha. */
		return '<p><em>' . esc_html( sprintf( __( 'Última actualización: %s.', 'dsmk-legal' ), wp_date( 'd/m/Y', $ts ? $ts : time() ) ) ) . '</em></p>';
	}

	/**
	 * Días de desistimiento.
	 *
	 * @since  2.0.0
	 * @return int
	 */
	private static function days() {
		return max( 14, (int) DSMKC_Settings::get( 'tienda_desist_dias', 14 ) );
	}

	/**
	 * Email y dirección para desistimientos.
	 *
	 * @since  2.0.0
	 * @return array{email:string, address:string} HTML escapado.
	 */
	private static function withdrawal_contact() {
		return array(
			'email'   => self::v( 'desist_email', 'email', 'titular_email' ),
			'address' => self::v( 'desist_direccion', __( 'domicilio', 'dsmk-legal' ), 'titular_domicilio' ),
		);
	}

	/* =====================================================================
	 * Términos y condiciones de servicios
	 * ===================================================================== */

	/**
	 * [dsmk_terminos_servicio]
	 *
	 * @since  2.0.0
	 * @return string HTML.
	 */
	public static function terms() {
		$s          = DSMKC_Settings::all();
		$consumers  = 'consumidores' === $s['serv_clientes'];
		$juris      = '' !== trim( $s['jurisdiccion'] ) ? esc_html( $s['jurisdiccion'] ) : esc_html__( 'del domicilio del titular', 'dsmk-legal' );
		$contract   = array(
			'presupuesto' => __( 'Cada encargo se formaliza mediante un presupuesto o propuesta que detalla el alcance, el precio y los plazos. El contrato se entiende celebrado cuando el cliente acepta expresamente el presupuesto por escrito (incluido el correo electrónico o la firma electrónica). Los trabajos no incluidos en el presupuesto se presupuestarán aparte.', 'dsmk-legal' ),
			'online'      => __( 'Los servicios se contratan a través de la web siguiendo el proceso indicado en ella. Antes de confirmar se muestra el resumen del servicio, el precio total con impuestos y estas condiciones, que el cliente debe aceptar. Tras la contratación se envía una confirmación por correo electrónico. El documento electrónico del contrato se archiva y el cliente puede solicitar una copia. El idioma del contrato es el español.', 'dsmk-legal' ),
			'mixta'       => __( 'Los servicios pueden contratarse a través de la web, siguiendo el proceso indicado en ella, o mediante la aceptación expresa por escrito de un presupuesto o propuesta que detalla el alcance, el precio y los plazos. En ambos casos se envía una confirmación por correo electrónico.', 'dsmk-legal' ),
		);
		$ownership  = 'licencia' === $s['serv_propiedad']
			? __( 'El titular conserva los derechos de propiedad intelectual sobre los trabajos realizados y concede al cliente, una vez abonado el precio completo, una licencia de uso no exclusiva para los fines acordados en el encargo. Cualquier otro uso requiere autorización.', 'dsmk-legal' )
			: __( 'Una vez abonado el precio completo, el titular cede al cliente los derechos de explotación (reproducción, distribución, comunicación pública y transformación) sobre los trabajos finales entregados, para los fines acordados, con el alcance territorial y temporal máximo permitido por la ley. Los derechos morales de autor son irrenunciables. Los archivos de trabajo, bocetos y propuestas no elegidas no se ceden salvo acuerdo expreso.', 'dsmk-legal' );

		ob_start();
		?>
<div class="dsmkc-legal dsmkc-legal--terminos">
<h2><?php esc_html_e( '1. Identificación del prestador', 'dsmk-legal' ); ?></h2>
<?php echo self::identity_list(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escapado en identity_list(). ?>

<h2><?php esc_html_e( '2. Objeto y aceptación', 'dsmk-legal' ); ?></h2>
<p><?php printf( esc_html__( 'Estas condiciones regulan la contratación de los servicios que presta %s. Al contratar un servicio el cliente declara haberlas leído y aceptado. Las condiciones particulares de cada presupuesto prevalecen sobre estas condiciones generales en lo que las contradigan.', 'dsmk-legal' ), self::v( 'titular_nombre', __( 'titular', 'dsmk-legal' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
<?php echo self::paragraphs( 'serv_descripcion' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<p><?php echo $consumers ? esc_html__( 'Los servicios se ofrecen tanto a empresas y profesionales como a consumidores. Cuando el cliente sea consumidor se aplicarán, además, los derechos que le reconoce la normativa de consumo, que prevalecen sobre estas condiciones.', 'dsmk-legal' ) : esc_html__( 'Los servicios se dirigen exclusivamente a empresas y profesionales que contratan en el ámbito de su actividad, por lo que no les es de aplicación la normativa de protección de consumidores.', 'dsmk-legal' ); ?></p>

<h2><?php esc_html_e( '3. Contratación', 'dsmk-legal' ); ?></h2>
<p><?php echo esc_html( isset( $contract[ $s['serv_contratacion'] ] ) ? $contract[ $s['serv_contratacion'] ] : $contract['presupuesto'] ); ?></p>

<h2><?php esc_html_e( '4. Precio y forma de pago', 'dsmk-legal' ); ?></h2>
<p><?php esc_html_e( 'El precio de cada servicio es el indicado en el presupuesto o en la web. Salvo que se indique lo contrario, los precios no incluyen el IVA ni otros impuestos aplicables, que se añadirán en la factura.', 'dsmk-legal' ); ?></p>
<?php echo self::paragraphs( 'serv_pago' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<p><?php echo $consumers ? esc_html__( 'El retraso en el pago podrá suponer la suspensión del servicio hasta su regularización, previo aviso al cliente.', 'dsmk-legal' ) : esc_html__( 'El retraso en el pago podrá suponer la suspensión del servicio hasta su regularización y devengará los intereses de demora previstos en la Ley 3/2004, de medidas de lucha contra la morosidad en las operaciones comerciales.', 'dsmk-legal' ); ?></p>

<h2><?php esc_html_e( '5. Plazos de ejecución', 'dsmk-legal' ); ?></h2>
<?php echo self::paragraphs( 'serv_plazos' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<p><?php esc_html_e( 'Los plazos se cuentan desde que el cliente acepta el presupuesto, realiza el pago inicial acordado y entrega la información y los materiales necesarios. Los retrasos del cliente en esas entregas o en sus respuestas desplazan los plazos en la misma medida.', 'dsmk-legal' ); ?></p>

<h2><?php esc_html_e( '6. Obligaciones del cliente', 'dsmk-legal' ); ?></h2>
<p><?php esc_html_e( 'El cliente se compromete a facilitar información veraz y completa, a colaborar en lo necesario para la prestación del servicio y a garantizar que dispone de los derechos sobre los textos, imágenes, marcas y demás materiales que entrega, manteniendo indemne al titular frente a cualquier reclamación de terceros por su uso.', 'dsmk-legal' ); ?></p>

<h2><?php esc_html_e( '7. Revisiones y aceptación del trabajo', 'dsmk-legal' ); ?></h2>
<p>
<?php
if ( '' !== trim( $s['serv_revisiones'] ) ) {
	/* translators: %s: revisiones incluidas. */
	printf( esc_html__( 'El precio incluye %s. Los cambios adicionales o los que supongan modificar el alcance acordado se presupuestarán aparte.', 'dsmk-legal' ), esc_html( $s['serv_revisiones'] ) );
} else {
	esc_html_e( 'Las revisiones incluidas son las indicadas en el presupuesto. Los cambios adicionales o los que supongan modificar el alcance acordado se presupuestarán aparte.', 'dsmk-legal' );
}
?>
 <?php esc_html_e( 'Si el cliente no comunica objeciones concretas en los 10 días hábiles siguientes a la entrega, el trabajo se entenderá aceptado.', 'dsmk-legal' ); ?></p>

<h2><?php esc_html_e( '8. Propiedad intelectual', 'dsmk-legal' ); ?></h2>
<p><?php echo esc_html( $ownership ); ?></p>
<?php if ( ! empty( $s['serv_portfolio'] ) ) : ?>
<p><?php esc_html_e( 'Salvo que el cliente se oponga por escrito o exista un acuerdo de confidencialidad, el titular podrá mostrar los trabajos realizados en su portfolio, web y redes sociales como muestra de su actividad.', 'dsmk-legal' ); ?></p>
<?php endif; ?>

<h2><?php esc_html_e( '9. Confidencialidad y protección de datos', 'dsmk-legal' ); ?></h2>
<p><?php printf( esc_html__( 'Ambas partes guardarán confidencialidad sobre la información no pública que conozcan con motivo del encargo. Si para prestar el servicio el titular debe acceder a datos personales de los que el cliente es responsable, ambas partes firmarán el contrato de encargo del tratamiento previsto en el artículo 28 del RGPD. El tratamiento de los datos del cliente se rige por la %s.', 'dsmk-legal' ), self::link( 'privacidad', __( 'política de privacidad', 'dsmk-legal' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>

<h2><?php esc_html_e( '10. Responsabilidad', 'dsmk-legal' ); ?></h2>
<p>
<?php
$limit = trim( (string) $s['serv_responsabilidad_max'] );
if ( $consumers ) {
	esc_html_e( 'El titular responde de la correcta prestación de los servicios conforme a la ley. No será responsable de los daños causados por servicios o proveedores de terceros que el cliente contrate directamente (alojamiento, dominios, plataformas publicitarias, etc.) ni por el uso que el cliente haga de los trabajos entregados.', 'dsmk-legal' );
} else {
	/* translators: %s: límite de responsabilidad. */
	printf( esc_html__( 'Salvo dolo o culpa grave, la responsabilidad total del titular frente al cliente por cualquier causa relacionada con el servicio se limita a %s. El titular no responde del lucro cesante ni de los daños indirectos, ni de los causados por servicios o proveedores de terceros que el cliente contrate directamente (alojamiento, dominios, plataformas publicitarias, etc.).', 'dsmk-legal' ), '' !== $limit ? esc_html( $limit ) : esc_html__( 'el importe abonado por el servicio afectado', 'dsmk-legal' ) );
}
?>
</p>

<h2><?php esc_html_e( '11. Cancelación', 'dsmk-legal' ); ?></h2>
<?php
$cancel = self::paragraphs( 'serv_cancelacion' );
echo '' !== $cancel ? $cancel : '<p>' . esc_html__( 'Si el cliente cancela un encargo ya iniciado, deberá abonar los trabajos realizados hasta la fecha de cancelación y los gastos en que se haya incurrido. Las cantidades pagadas por adelantado se regularizarán en consecuencia.', 'dsmk-legal' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
?>
<?php if ( $consumers ) : ?>
<h3><?php esc_html_e( 'Derecho de desistimiento de los consumidores', 'dsmk-legal' ); ?></h3>
<p><?php printf( esc_html__( 'Si el cliente es consumidor y el contrato se celebra a distancia, dispone de 14 días naturales desde la celebración del contrato para desistir sin necesidad de justificación. Si solicita expresamente que el servicio comience durante ese plazo, deberá abonar la parte proporcional de lo prestado hasta que comunique el desistimiento. El derecho de desistimiento no se aplica cuando el servicio se haya ejecutado completamente con su consentimiento expreso y su reconocimiento de que perdería este derecho (art. 103.a del Real Decreto Legislativo 1/2007). Más información y formulario en %s.', 'dsmk-legal' ), self::link( 'desistimiento', __( 'Derecho de desistimiento', 'dsmk-legal' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
<?php endif; ?>

<h2><?php esc_html_e( '12. Legislación aplicable y jurisdicción', 'dsmk-legal' ); ?></h2>
<p><?php printf( esc_html__( 'Estas condiciones se rigen por la legislación española. Si el cliente es consumidor, serán competentes los juzgados y tribunales de su domicilio; en el resto de casos, las partes se someten a los juzgados y tribunales de %s.', 'dsmk-legal' ), $juris ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
<?php echo self::extra( 'terminos' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<?php echo self::updated(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</div>
		<?php
		return (string) ob_get_clean();
	}

	/* =====================================================================
	 * Condiciones generales de venta
	 * ===================================================================== */

	/**
	 * Formas de pago configuradas, en texto.
	 *
	 * @since  2.0.0
	 * @return string[]
	 */
	private static function payment_labels() {
		$labels = array(
			'tarjeta'         => __( 'tarjeta de crédito o débito', 'dsmk-legal' ),
			'paypal'          => 'PayPal',
			'bizum'           => 'Bizum',
			'transferencia'   => __( 'transferencia bancaria (el pedido se envía al recibir el pago)', 'dsmk-legal' ),
			'contrareembolso' => __( 'contra reembolso', 'dsmk-legal' ),
			'wallet'          => 'Apple Pay / Google Pay',
		);
		$out = array();
		foreach ( (array) DSMKC_Settings::get( 'tienda_pagos', array() ) as $key ) {
			if ( isset( $labels[ $key ] ) ) {
				$out[] = $labels[ $key ];
			}
		}
		return $out;
	}

	/**
	 * Excepciones al desistimiento configuradas (art. 103 TRLGDCU).
	 *
	 * @since  2.0.0
	 * @return string[]
	 */
	private static function exceptions() {
		$labels = array(
			'personalizados' => __( 'Productos confeccionados conforme a las especificaciones del cliente o claramente personalizados.', 'dsmk-legal' ),
			'perecederos'    => __( 'Productos que puedan deteriorarse o caducar con rapidez.', 'dsmk-legal' ),
			'precintados'    => __( 'Productos precintados que no sean aptos para ser devueltos por razones de protección de la salud o de higiene y que hayan sido desprecintados tras la entrega.', 'dsmk-legal' ),
			'grabaciones'    => __( 'Grabaciones de sonido o de vídeo precintadas o programas informáticos precintados que hayan sido desprecintados tras la entrega.', 'dsmk-legal' ),
			'digitales'      => __( 'Contenidos digitales sin soporte material cuando la descarga o el acceso haya comenzado con el consentimiento expreso del cliente y su reconocimiento de que pierde el derecho de desistimiento.', 'dsmk-legal' ),
			'mezclados'      => __( 'Productos que, después de su entrega, se hayan mezclado de forma indisociable con otros bienes.', 'dsmk-legal' ),
		);
		$out = array();
		foreach ( (array) DSMKC_Settings::get( 'tienda_excepciones', array() ) as $key ) {
			if ( isset( $labels[ $key ] ) ) {
				$out[] = $labels[ $key ];
			}
		}
		return $out;
	}

	/**
	 * [dsmk_condiciones_venta]
	 *
	 * @since  2.0.0
	 * @return string HTML.
	 */
	public static function conditions() {
		$s        = DSMKC_Settings::all();
		$days     = self::days();
		$contact  = self::withdrawal_contact();
		$payments = self::payment_labels();
		$except   = self::exceptions();
		$vat      = 'iva_no_incluido' === $s['tienda_impuestos'] ? __( 'Los precios se indican en euros y no incluyen el IVA, que se añade y se muestra desglosado antes de finalizar la compra.', 'dsmk-legal' ) : __( 'Los precios se indican en euros e incluyen el IVA.', 'dsmk-legal' );

		ob_start();
		?>
<div class="dsmkc-legal dsmkc-legal--condiciones">
<h2><?php esc_html_e( '1. Identificación del vendedor', 'dsmk-legal' ); ?></h2>
<?php echo self::identity_list(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<p><?php printf( esc_html__( 'Estas condiciones regulan las compras realizadas en %1$s y se rigen por el Real Decreto Legislativo 1/2007 (Ley General para la Defensa de los Consumidores y Usuarios), la Ley 7/1998 sobre condiciones generales de la contratación y la Ley 34/2002 (LSSI). Antes de comprar, el cliente debe aceptarlas expresamente. La versión aplicable es la vigente en el momento del pedido. Para comprar es necesario ser mayor de edad.', 'dsmk-legal' ), esc_html( DSMKC_Legal::site_name() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>

<h2><?php esc_html_e( '2. Productos y precios', 'dsmk-legal' ); ?></h2>
<?php if ( '' !== trim( $s['tienda_productos'] ) ) : ?><p><?php echo esc_html( $s['tienda_productos'] ); ?></p><?php endif; ?>
<p><?php echo esc_html( $vat ); ?> <?php echo esc_html( '' !== trim( $s['tienda_coste_envio'] ) ? $s['tienda_coste_envio'] : __( 'Los gastos de envío se indican antes de finalizar la compra y se suman al precio de los productos.', 'dsmk-legal' ) ); ?> <?php esc_html_e( 'Los precios pueden cambiar, pero se aplica siempre el vigente en el momento de realizar el pedido. Las imágenes de los productos son orientativas.', 'dsmk-legal' ); ?></p>

<h2><?php esc_html_e( '3. Proceso de compra', 'dsmk-legal' ); ?></h2>
<ol>
	<li><?php esc_html_e( 'Añade los productos al carrito y accede al proceso de compra.', 'dsmk-legal' ); ?></li>
	<li><?php esc_html_e( 'Introduce tus datos de facturación y envío y elige la forma de envío y de pago.', 'dsmk-legal' ); ?></li>
	<li><?php esc_html_e( 'Revisa el resumen del pedido, con el precio total, impuestos y gastos de envío, y acepta estas condiciones.', 'dsmk-legal' ); ?></li>
	<li><?php esc_html_e( 'Pulsa el botón de pedido con obligación de pago y completa el pago.', 'dsmk-legal' ); ?></li>
</ol>
<p><?php esc_html_e( 'Recibirás por correo electrónico la confirmación del pedido. El documento electrónico del contrato se archiva y puedes pedirnos una copia. Puedes corregir los errores en tus datos antes de confirmar el pedido. El idioma del contrato es el español.', 'dsmk-legal' ); ?></p>

<h2><?php esc_html_e( '4. Formas de pago', 'dsmk-legal' ); ?></h2>
<p>
<?php
if ( $payments ) {
	/* translators: %s: formas de pago. */
	printf( esc_html__( 'Puedes pagar mediante: %s.', 'dsmk-legal' ), esc_html( DSMKC_Legal::human_list( $payments ) ) );
} else {
	esc_html_e( 'Las formas de pago disponibles se muestran en el proceso de compra.', 'dsmk-legal' );
}
?>
 <?php esc_html_e( 'Los pagos con tarjeta se procesan mediante pasarelas seguras: no almacenamos los datos de tu tarjeta.', 'dsmk-legal' ); ?></p>

<h2><?php esc_html_e( '5. Envío y entrega', 'dsmk-legal' ); ?></h2>
<p>
<?php
/* translators: 1: zonas de envío, 2: plazo. */
printf( esc_html__( 'Realizamos envíos a: %1$s. Plazo de entrega habitual: %2$s desde la confirmación del pago. En cualquier caso, la entrega se realizará en un plazo máximo de 30 días naturales desde el pedido; si no fuera posible, te avisaremos y podrás cancelar el pedido con reembolso de las cantidades pagadas.', 'dsmk-legal' ), self::v( 'tienda_ambito', __( 'zonas de envío', 'dsmk-legal' ) ), self::v( 'tienda_plazo_envio', __( 'plazo de entrega', 'dsmk-legal' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
?>
 <?php esc_html_e( 'El riesgo de pérdida o deterioro de los productos se transmite al cliente cuando los recibe. Si el paquete llega dañado, indícalo al transportista y comunícanoslo lo antes posible.', 'dsmk-legal' ); ?></p>

<h2><?php esc_html_e( '6. Derecho de desistimiento', 'dsmk-legal' ); ?></h2>
<p><?php printf( esc_html__( 'Si eres consumidor, puedes desistir de la compra sin necesidad de justificación en un plazo de %1$d días naturales desde que recibes el producto (o el último producto, si el pedido llega en varios envíos). Para ejercerlo basta con comunicárnoslo de forma inequívoca a %2$s o a %3$s, por ejemplo con el formulario modelo, o desde la página %4$s.', 'dsmk-legal' ), (int) $days, $contact['email'], $contact['address'], self::link( 'desistimiento', __( 'Derecho de desistimiento', 'dsmk-legal' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
<p><?php echo esc_html( 'tienda' === $s['tienda_devolucion_coste'] ? __( 'Los gastos de devolución corren de nuestra cuenta.', 'dsmk-legal' ) : __( 'Los gastos directos de devolución corren a cargo del cliente.', 'dsmk-legal' ) ); ?> <?php esc_html_e( 'Debes devolver los productos en un plazo máximo de 14 días naturales desde que nos comunicas el desistimiento. Te reembolsaremos todos los pagos recibidos, incluidos los gastos de envío estándar, en un plazo máximo de 14 días naturales desde la comunicación, utilizando el mismo medio de pago; podemos retener el reembolso hasta recibir los productos o la prueba de su envío. Solo responderás de la disminución de valor de los productos resultante de una manipulación distinta de la necesaria para comprobar su naturaleza, características y funcionamiento.', 'dsmk-legal' ); ?></p>
<?php if ( $except ) : ?>
<p><?php esc_html_e( 'No se aplica el derecho de desistimiento (art. 103 del Real Decreto Legislativo 1/2007) a:', 'dsmk-legal' ); ?></p>
<ul><?php foreach ( $except as $line ) : ?><li><?php echo esc_html( $line ); ?></li><?php endforeach; ?></ul>
<?php endif; ?>

<h2><?php esc_html_e( '7. Garantía legal', 'dsmk-legal' ); ?></h2>
<p><?php esc_html_e( 'Respondemos de las faltas de conformidad de los productos que se manifiesten en un plazo de tres años desde la entrega (dos años en el caso de contenidos o servicios digitales), conforme a los artículos 114 y siguientes del Real Decreto Legislativo 1/2007. Ante una falta de conformidad puedes elegir entre la reparación o la sustitución del producto, salvo que una de ellas sea imposible o desproporcionada y, en su caso, la rebaja del precio o la resolución del contrato. Para ejercer la garantía, contacta con nosotros con el número de pedido y una descripción del problema.', 'dsmk-legal' ); ?></p>

<h2><?php esc_html_e( '8. Atención al cliente y reclamaciones', 'dsmk-legal' ); ?></h2>
<p><?php printf( esc_html__( 'Puedes contactar con nosotros en %1$s%2$s. Atenderemos tus reclamaciones en el menor plazo posible y, en todo caso, en un máximo de 15 días hábiles. Tienes a tu disposición hojas de reclamaciones oficiales y puedes acudir a los organismos de consumo de tu comunidad autónoma.', 'dsmk-legal' ), self::v( 'titular_email', 'email' ), '' !== trim( $s['tienda_atencion'] ) ? ' (' . esc_html( $s['tienda_atencion'] ) . ')' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>

<h2><?php esc_html_e( '9. Protección de datos', 'dsmk-legal' ); ?></h2>
<p><?php printf( esc_html__( 'Los datos que nos facilitas para la compra se tratan conforme a nuestra %s.', 'dsmk-legal' ), self::link( 'privacidad', __( 'política de privacidad', 'dsmk-legal' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>

<h2><?php esc_html_e( '10. Legislación aplicable y jurisdicción', 'dsmk-legal' ); ?></h2>
<p><?php esc_html_e( 'Estas condiciones se rigen por la legislación española. Si eres consumidor, serán competentes los juzgados y tribunales de tu domicilio.', 'dsmk-legal' ); ?></p>
<?php echo self::extra( 'condiciones' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<?php echo self::updated(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</div>
		<?php
		return (string) ob_get_clean();
	}

	/* =====================================================================
	 * Desistimiento
	 * ===================================================================== */

	/**
	 * [dsmk_desistimiento]: información (anexo A) y formulario modelo (anexo B).
	 *
	 * @since  2.0.0
	 * @return string HTML.
	 */
	public static function withdrawal() {
		$s       = DSMKC_Settings::all();
		$days    = self::days();
		$contact = self::withdrawal_contact();
		$except  = self::exceptions();

		ob_start();
		?>
<div class="dsmkc-legal dsmkc-legal--desistimiento">
<p><?php printf( esc_html__( 'Tienes derecho a desistir del contrato en un plazo de %d días naturales sin necesidad de justificación.', 'dsmk-legal' ), (int) $days ); ?></p>
<p><?php printf( esc_html__( 'El plazo de desistimiento expirará a los %d días naturales del día en que tú o un tercero indicado por ti, distinto del transportista, adquiristeis la posesión material de los bienes (o del último de ellos, si se entregan por separado). En los contratos de servicios, el plazo cuenta desde la celebración del contrato.', 'dsmk-legal' ), (int) $days ); ?></p>
<p><?php printf( esc_html__( 'Para ejercer el derecho de desistimiento, debes notificarnos tu decisión de desistir del contrato a través de una declaración inequívoca a %1$s, con domicilio en %2$s, email %3$s. Puedes utilizar el formulario de desistimiento que aparece en esta página, aunque su uso no es obligatorio. Para cumplir el plazo basta con que la comunicación se envíe antes de que venza.', 'dsmk-legal' ), self::v( 'titular_nombre', __( 'titular', 'dsmk-legal' ) ), $contact['address'], $contact['email'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>

<?php echo self::withdrawal_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ver withdrawal_form(). ?>

<h3><?php esc_html_e( 'Consecuencias del desistimiento', 'dsmk-legal' ); ?></h3>
<p><?php esc_html_e( 'Te devolveremos todos los pagos recibidos, incluidos los gastos de entrega (salvo los costes adicionales si elegiste una modalidad de entrega distinta de la menos costosa que ofrecemos), sin demora indebida y, en todo caso, a más tardar 14 días naturales a partir de la fecha en que nos informes de tu decisión de desistir. Utilizaremos el mismo medio de pago que empleaste, salvo que acordemos otro, y no incurrirás en ningún gasto como consecuencia del reembolso. Podremos retener el reembolso hasta haber recibido los bienes o hasta que presentes una prueba de su devolución, según qué condición se cumpla primero.', 'dsmk-legal' ); ?></p>
<p><?php printf( esc_html__( 'Deberás devolvernos los bienes a %s sin demora indebida y, en cualquier caso, a más tardar en 14 días naturales a partir de la fecha en que nos comuniques tu decisión de desistir.', 'dsmk-legal' ), $contact['address'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html( 'tienda' === $s['tienda_devolucion_coste'] ? __( 'Nosotros asumimos el coste de la devolución.', 'dsmk-legal' ) : __( 'Deberás asumir el coste directo de devolución de los bienes.', 'dsmk-legal' ) ); ?> <?php esc_html_e( 'Solo serás responsable de la disminución de valor de los bienes resultante de una manipulación distinta a la necesaria para establecer su naturaleza, sus características o su funcionamiento.', 'dsmk-legal' ); ?></p>
<p><?php esc_html_e( 'Si solicitaste que la prestación de un servicio comenzara durante el plazo de desistimiento, deberás abonarnos un importe proporcional a la parte ya prestada en el momento en que nos comuniques el desistimiento.', 'dsmk-legal' ); ?></p>
<?php if ( $except ) : ?>
<h3><?php esc_html_e( 'Excepciones', 'dsmk-legal' ); ?></h3>
<ul><?php foreach ( $except as $line ) : ?><li><?php echo esc_html( $line ); ?></li><?php endforeach; ?></ul>
<?php endif; ?>

<h3><?php esc_html_e( 'Formulario modelo de desistimiento', 'dsmk-legal' ); ?></h3>
<p><em><?php esc_html_e( '(Solo debe cumplimentar y enviar el presente formulario si desea desistir del contrato.)', 'dsmk-legal' ); ?></em></p>
<div class="dsmkc-model-form">
<p><?php printf( esc_html__( 'A la atención de %1$s, %2$s, %3$s:', 'dsmk-legal' ), self::v( 'titular_nombre', __( 'titular', 'dsmk-legal' ) ), $contact['address'], $contact['email'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
<p><?php esc_html_e( 'Por la presente le comunico/comunicamos (*) que desisto de mi/desistimos de nuestro (*) contrato de venta del siguiente bien/prestación del siguiente servicio (*):', 'dsmk-legal' ); ?></p>
<p><?php esc_html_e( 'Pedido el/recibido el (*):', 'dsmk-legal' ); ?></p>
<p><?php esc_html_e( 'Nombre del consumidor o de los consumidores:', 'dsmk-legal' ); ?></p>
<p><?php esc_html_e( 'Domicilio del consumidor o de los consumidores:', 'dsmk-legal' ); ?></p>
<p><?php esc_html_e( 'Firma del consumidor o de los consumidores (solo si el presente formulario se presenta en papel):', 'dsmk-legal' ); ?></p>
<p><?php esc_html_e( 'Fecha:', 'dsmk-legal' ); ?></p>
<p><small><?php esc_html_e( '(*) Táchese lo que no proceda.', 'dsmk-legal' ); ?></small></p>
</div>
<?php echo self::extra( 'desistimiento' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<?php echo self::updated(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * [dsmk_desistimiento_formulario]: función de desistimiento online.
	 *
	 * Punto de integración: el plugin de desistimiento de WooCommerce de DSMK
	 * debe engancharse a `dsmkc_withdrawal_form` y devolver su formulario o
	 * botón funcional (Directiva UE 2023/2673, aplicable desde el 19/06/2026).
	 * Sin ese plugin se muestra una alternativa por email con el asunto ya
	 * preparado.
	 *
	 * @since  2.0.0
	 * @return string HTML.
	 */
	public static function withdrawal_form() {
		/**
		 * HTML de la función de desistimiento online.
		 *
		 * @since 2.0.0
		 * @param string $html HTML ('' = usar la alternativa por email).
		 */
		$html = (string) apply_filters( 'dsmkc_withdrawal_form', '' );
		if ( '' !== $html ) {
			return '<div class="dsmkc-withdrawal-form">' . $html . '</div>';
		}
		$email = trim( (string) DSMKC_Settings::get( 'desist_email', '' ) );
		$email = '' !== $email ? $email : trim( (string) DSMKC_Settings::get( 'titular_email', '' ) );
		if ( ! is_email( $email ) ) {
			return '';
		}
		$subject = rawurlencode( __( 'Desistimiento del pedido nº ', 'dsmk-legal' ) );
		$body    = rawurlencode( __( "Por la presente comunico que desisto de mi contrato.\nNº de pedido:\nFecha de pedido / recepción:\nNombre:\nDomicilio:\nFecha:", 'dsmk-legal' ) );
		return '<p class="dsmkc-withdrawal-form"><a class="dsmkc-withdrawal-button" href="mailto:' . esc_attr( $email ) . '?subject=' . $subject . '&amp;body=' . $body . '">' . esc_html__( 'Desistir del contrato aquí', 'dsmk-legal' ) . '</a></p>';
	}
}
