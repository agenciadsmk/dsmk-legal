<?php
/**
 * DSMK Legal — Ajustes: esquema, valores por defecto, saneado y acceso.
 *
 * @package   DSMK_Legal
 * @file      includes/class-dsmkc-settings.php
 * @since     1.0.0
 * @author    DSMK Agencia Digital <hola@dsmk.es>
 * @license   GPL-2.0-or-later
 *
 * Responsabilidad:
 *   Un único esquema declarativo (schema()) define cada campo de configuración:
 *   tipo, valor por defecto, etiqueta, ayuda y restricciones. A partir de él se
 *   generan los valores por defecto, el saneado al guardar y el formulario del
 *   panel de administración. Así no hay campos "huérfanos" sin sanear.
 *
 * Almacenamiento:
 *   - Opción `dsmkc_settings` (array): configuración editable por el usuario.
 *   - Opción `dsmkc_data` (array): estado interno (detecciones, versión de la
 *     política, token de escaneo...). Ver DSMKC_Settings::data().
 *
 * Seguridad:
 *   - Todo valor que entra pasa por sanitize_field() según su tipo declarado.
 *   - Los campos HTML solo admiten etiquetas de wp_kses_post().
 *   - El CSS extra se limpia de etiquetas y de expresiones peligrosas.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Gestor de ajustes del plugin.
 *
 * @since 1.0.0
 */
final class DSMKC_Settings {

	/** @var string Nombre de la opción con la configuración. */
	const OPTION = 'dsmkc_settings';

	/** @var string Nombre de la opción con el estado interno. */
	const DATA = 'dsmkc_data';

	/** @var array|null Caché en memoria de la configuración. */
	private static $cache = null;

	/** @var array|null Caché en memoria del estado interno. */
	private static $data_cache = null;

	/**
	 * Esquema completo de configuración agrupado por pestañas.
	 *
	 * Tipos admitidos: text, textarea, html, email, url, color, int, bool,
	 * select, multicheck, page, css.
	 *
	 * @since  1.0.0
	 * @return array<string, array{title:string, fields:array<string, array>}>
	 */
	public static function schema() {
		$schema = array(
			'titular'       => array(
				'title'  => __( 'Datos del titular', 'dsmk-legal' ),
				'intro'  => __( 'Con estos datos se generan automáticamente el aviso legal, la política de privacidad, la política de cookies y el texto del banner.', 'dsmk-legal' ),
				'fields' => array(
					'titular_nombre'    => array( 'type' => 'text', 'default' => '', 'label' => __( 'Titular (nombre o razón social)', 'dsmk-legal' ), 'required' => true ),
					'titular_tipo'      => array(
						'type'    => 'select',
						'default' => 'sociedad',
						'label'   => __( 'Tipo de titular', 'dsmk-legal' ),
						'options' => array(
							'sociedad'       => __( 'Sociedad mercantil', 'dsmk-legal' ),
							'autonomo'       => __( 'Persona física / autónomo', 'dsmk-legal' ),
							'asociacion'     => __( 'Asociación / fundación / entidad sin ánimo de lucro', 'dsmk-legal' ),
							'administracion' => __( 'Administración pública', 'dsmk-legal' ),
						),
					),
					'titular_nif'       => array( 'type' => 'text', 'default' => '', 'label' => __( 'NIF / CIF', 'dsmk-legal' ), 'required' => true ),
					'titular_domicilio' => array( 'type' => 'text', 'default' => '', 'label' => __( 'Domicilio completo', 'dsmk-legal' ), 'required' => true ),
					'titular_email'     => array( 'type' => 'email', 'default' => '', 'label' => __( 'Email de contacto', 'dsmk-legal' ), 'required' => true ),
					'titular_telefono'  => array( 'type' => 'text', 'default' => '', 'label' => __( 'Teléfono', 'dsmk-legal' ) ),
					'titular_registro'  => array( 'type' => 'textarea', 'default' => '', 'label' => __( 'Datos registrales', 'dsmk-legal' ), 'help' => __( 'Sociedades: Registro Mercantil, tomo, folio, hoja e inscripción. Asociaciones: registro y número.', 'dsmk-legal' ) ),
					'titular_colegio'   => array( 'type' => 'textarea', 'default' => '', 'label' => __( 'Profesión regulada (opcional)', 'dsmk-legal' ), 'help' => __( 'Colegio profesional, número de colegiado, título y Estado que lo expidió, normas aplicables.', 'dsmk-legal' ) ),
					'titular_actividad' => array( 'type' => 'text', 'default' => '', 'label' => __( 'Actividad de la web', 'dsmk-legal' ), 'help' => __( 'Ej.: «información sobre los servicios de fotografía de la empresa y venta online de productos».', 'dsmk-legal' ) ),
					'titular_dpo'       => array( 'type' => 'email', 'default' => '', 'label' => __( 'Email del Delegado de Protección de Datos (si lo hay)', 'dsmk-legal' ) ),
					'hosting_proveedor' => array( 'type' => 'text', 'default' => '', 'label' => __( 'Proveedor de alojamiento web', 'dsmk-legal' ), 'help' => __( 'Se cita como encargado del tratamiento en la política de privacidad.', 'dsmk-legal' ) ),
					'finalidades'       => array(
						'type'    => 'multicheck',
						'default' => array( 'contacto' ),
						'label'   => __( 'Datos que se recogen en la web', 'dsmk-legal' ),
						'options' => array(
							'contacto'   => __( 'Formulario de contacto', 'dsmk-legal' ),
							'presupuesto'=> __( 'Solicitud de presupuesto', 'dsmk-legal' ),
							'tienda'     => __( 'Tienda online (pedidos y clientes)', 'dsmk-legal' ),
							'servicios'  => __( 'Clientes de servicios (encargos y facturación)', 'dsmk-legal' ),
							'registro'   => __( 'Registro de usuarios / área privada', 'dsmk-legal' ),
							'newsletter' => __( 'Newsletter / comunicaciones comerciales', 'dsmk-legal' ),
							'empleo'     => __( 'Envío de currículums', 'dsmk-legal' ),
							'comentarios'=> __( 'Comentarios en el blog', 'dsmk-legal' ),
							'reservas'   => __( 'Reservas / citas', 'dsmk-legal' ),
						),
					),
					'jurisdiccion'      => array( 'type' => 'text', 'default' => '', 'label' => __( 'Juzgados competentes (ciudad)', 'dsmk-legal' ), 'help' => __( 'Para usuarios no consumidores. Con consumidores siempre rige su domicilio.', 'dsmk-legal' ) ),
				),
			),
			'banner'        => array(
				'title'  => __( 'Banner', 'dsmk-legal' ),
				'intro'  => __( 'Los botones «Aceptar» y «Rechazar» siempre se muestran juntos en la primera capa y con el mismo diseño (Guía AEPD). Deja los textos vacíos para usar los textos automáticos.', 'dsmk-legal' ),
				'fields' => array(
					'banner_posicion'  => array(
						'type'    => 'select',
						'default' => 'caja_izquierda',
						'label'   => __( 'Formato y posición', 'dsmk-legal' ),
						'options' => array(
							'barra_inferior' => __( 'Barra inferior a todo el ancho', 'dsmk-legal' ),
							'barra_superior' => __( 'Barra superior a todo el ancho', 'dsmk-legal' ),
							'caja_izquierda' => __( 'Caja flotante abajo a la izquierda', 'dsmk-legal' ),
							'caja_derecha'   => __( 'Caja flotante abajo a la derecha', 'dsmk-legal' ),
							'modal'          => __( 'Ventana centrada', 'dsmk-legal' ),
						),
					),
					'banner_titulo'    => array( 'type' => 'text', 'default' => '', 'label' => __( 'Título', 'dsmk-legal' ), 'placeholder' => __( 'Usamos cookies', 'dsmk-legal' ) ),
					'banner_texto'     => array( 'type' => 'html', 'default' => '', 'label' => __( 'Texto de la primera capa', 'dsmk-legal' ), 'help' => __( 'Vacío = texto automático según los servicios detectados (recomendado). Variables: {titular}, {finalidades}, {terceros}, {politica}.', 'dsmk-legal' ) ),
					'btn_aceptar'      => array( 'type' => 'text', 'default' => '', 'label' => __( 'Botón aceptar', 'dsmk-legal' ), 'placeholder' => __( 'Aceptar todas', 'dsmk-legal' ) ),
					'btn_rechazar'     => array( 'type' => 'text', 'default' => '', 'label' => __( 'Botón rechazar', 'dsmk-legal' ), 'placeholder' => __( 'Rechazar todas', 'dsmk-legal' ) ),
					'btn_configurar'   => array( 'type' => 'text', 'default' => '', 'label' => __( 'Botón configurar', 'dsmk-legal' ), 'placeholder' => __( 'Configurar', 'dsmk-legal' ) ),
					'btn_guardar'      => array( 'type' => 'text', 'default' => '', 'label' => __( 'Botón guardar selección', 'dsmk-legal' ), 'placeholder' => __( 'Guardar mi selección', 'dsmk-legal' ) ),
					'revisit_mostrar'  => array( 'type' => 'bool', 'default' => 1, 'label' => __( 'Mostrar botón flotante para cambiar el consentimiento', 'dsmk-legal' ), 'help' => __( 'Recomendado: permite revocar en cualquier momento. Si lo desactivas, enlaza «#dsmk-cookies» en el pie.', 'dsmk-legal' ) ),
					'revisit_posicion' => array(
						'type'    => 'select',
						'default' => 'izquierda',
						'label'   => __( 'Posición del botón flotante', 'dsmk-legal' ),
						'options' => array( 'izquierda' => __( 'Abajo a la izquierda', 'dsmk-legal' ), 'derecha' => __( 'Abajo a la derecha', 'dsmk-legal' ) ),
					),
					'consent_dias'     => array( 'type' => 'int', 'default' => 365, 'min' => 1, 'max' => 730, 'label' => __( 'Validez del consentimiento (días)', 'dsmk-legal' ), 'help' => __( 'Máximo 730 (24 meses, recomendación AEPD). Pasado el plazo se vuelve a preguntar.', 'dsmk-legal' ) ),
					'gpc'              => array( 'type' => 'bool', 'default' => 0, 'label' => __( 'Respetar la señal «Global Privacy Control» del navegador como rechazo', 'dsmk-legal' ) ),
				),
			),
			'estilo'        => array(
				'title'  => __( 'Estilo', 'dsmk-legal' ),
				'intro'  => __( 'En modo «Heredar» el banner toma los colores y la tipografía globales de Elementor (o del tema). En modo «Personalizado» usa los valores de abajo.', 'dsmk-legal' ),
				'fields' => array(
					'estilo_modo'         => array(
						'type'    => 'select',
						'default' => 'heredar',
						'label'   => __( 'Modo de estilo', 'dsmk-legal' ),
						'options' => array(
							'heredar'       => __( 'Heredar estilos globales (Elementor / tema)', 'dsmk-legal' ),
							'personalizado' => __( 'Personalizado', 'dsmk-legal' ),
						),
					),
					'color_fondo'         => array( 'type' => 'color', 'default' => '#ffffff', 'label' => __( 'Fondo', 'dsmk-legal' ) ),
					'color_texto'         => array( 'type' => 'color', 'default' => '#1f2937', 'label' => __( 'Texto', 'dsmk-legal' ) ),
					'color_primario'      => array( 'type' => 'color', 'default' => '#1f2937', 'label' => __( 'Botones aceptar / rechazar (fondo)', 'dsmk-legal' ) ),
					'color_texto_primario'=> array( 'type' => 'color', 'default' => '#ffffff', 'label' => __( 'Botones aceptar / rechazar (texto)', 'dsmk-legal' ) ),
					'color_enlace'        => array( 'type' => 'color', 'default' => '#1d4ed8', 'label' => __( 'Enlaces e interruptores activos', 'dsmk-legal' ) ),
					'radio'               => array( 'type' => 'int', 'default' => 8, 'min' => 0, 'max' => 40, 'label' => __( 'Radio de esquinas (px)', 'dsmk-legal' ) ),
					'fuente'              => array( 'type' => 'text', 'default' => '', 'label' => __( 'Tipografía (font-family)', 'dsmk-legal' ), 'help' => __( 'Vacío = la de la web.', 'dsmk-legal' ) ),
					'tamano_fuente'       => array( 'type' => 'int', 'default' => 15, 'min' => 11, 'max' => 22, 'label' => __( 'Tamaño de letra (px)', 'dsmk-legal' ) ),
					'ancho_max'           => array( 'type' => 'int', 'default' => 440, 'min' => 280, 'max' => 1400, 'label' => __( 'Ancho máximo de la caja (px)', 'dsmk-legal' ) ),
					'sombra'              => array( 'type' => 'bool', 'default' => 1, 'label' => __( 'Sombra', 'dsmk-legal' ) ),
					'css_extra'           => array( 'type' => 'css', 'default' => '', 'label' => __( 'CSS adicional', 'dsmk-legal' ), 'help' => __( 'Clases disponibles: .dsmkc-banner, .dsmkc-panel, .dsmkc-btn, .dsmkc-revisit.', 'dsmk-legal' ) ),
				),
			),
			'categorias'    => array(
				'title'  => __( 'Textos de categorías', 'dsmk-legal' ),
				'intro'  => __( 'Textos que ve el usuario en el panel de configuración. Vacío = texto por defecto.', 'dsmk-legal' ),
				'fields' => array(
					'cat_necessary_nombre'   => array( 'type' => 'text', 'default' => '', 'placeholder' => __( 'Técnicas (necesarias)', 'dsmk-legal' ), 'label' => __( 'Necesarias: nombre', 'dsmk-legal' ) ),
					'cat_necessary_desc'     => array( 'type' => 'textarea', 'default' => '', 'label' => __( 'Necesarias: descripción', 'dsmk-legal' ) ),
					'cat_preferences_nombre' => array( 'type' => 'text', 'default' => '', 'placeholder' => __( 'Preferencias', 'dsmk-legal' ), 'label' => __( 'Preferencias: nombre', 'dsmk-legal' ) ),
					'cat_preferences_desc'   => array( 'type' => 'textarea', 'default' => '', 'label' => __( 'Preferencias: descripción', 'dsmk-legal' ) ),
					'cat_analytics_nombre'   => array( 'type' => 'text', 'default' => '', 'placeholder' => __( 'Analíticas', 'dsmk-legal' ), 'label' => __( 'Analíticas: nombre', 'dsmk-legal' ) ),
					'cat_analytics_desc'     => array( 'type' => 'textarea', 'default' => '', 'label' => __( 'Analíticas: descripción', 'dsmk-legal' ) ),
					'cat_marketing_nombre'   => array( 'type' => 'text', 'default' => '', 'placeholder' => __( 'Publicidad y contenidos de terceros', 'dsmk-legal' ), 'label' => __( 'Publicidad: nombre', 'dsmk-legal' ) ),
					'cat_marketing_desc'     => array( 'type' => 'textarea', 'default' => '', 'label' => __( 'Publicidad: descripción', 'dsmk-legal' ) ),
				),
			),
			'integraciones' => array(
				'title'  => __( 'Integraciones', 'dsmk-legal' ),
				'intro'  => __( 'Si pegas aquí los identificadores, el plugin inserta los códigos ya bloqueados hasta el consentimiento. No los insertes además en el tema o en otro plugin.', 'dsmk-legal' ),
				'fields' => array(
					'ga4_id'                 => array( 'type' => 'text', 'default' => '', 'label' => __( 'Google Analytics 4 (G-XXXXXXX)', 'dsmk-legal' ), 'pattern' => '/^G-[A-Z0-9]{4,}$/' ),
					'gtm_id'                 => array( 'type' => 'text', 'default' => '', 'label' => __( 'Google Tag Manager (GTM-XXXXXX)', 'dsmk-legal' ), 'pattern' => '/^GTM-[A-Z0-9]{4,}$/' ),
					'ads_id'                 => array( 'type' => 'text', 'default' => '', 'label' => __( 'Google Ads (AW-XXXXXXXXX)', 'dsmk-legal' ), 'pattern' => '/^AW-[0-9]{5,}$/' ),
					'pixel_id'               => array( 'type' => 'text', 'default' => '', 'label' => __( 'Meta Pixel (ID numérico)', 'dsmk-legal' ), 'pattern' => '/^[0-9]{6,20}$/' ),
					'clarity_id'             => array( 'type' => 'text', 'default' => '', 'label' => __( 'Microsoft Clarity (ID del proyecto)', 'dsmk-legal' ), 'pattern' => '/^[a-z0-9]{6,20}$/' ),
					'gtm_categoria'          => array(
						'type'    => 'select',
						'default' => 'analytics',
						'label'   => __( 'Google Tag Manager se carga con…', 'dsmk-legal' ),
						'options' => array(
							'analytics' => __( 'Consentimiento de analíticas (recomendado)', 'dsmk-legal' ),
							'marketing' => __( 'Consentimiento de publicidad', 'dsmk-legal' ),
						),
						'help'    => __( 'Las etiquetas publicitarias dentro de GTM deben usar la comprobación de consentimiento de Consent Mode (ad_storage). El plugin envía el evento «dsmkc_consent_update» al dataLayer.', 'dsmk-legal' ),
					),
					'consent_mode'           => array( 'type' => 'bool', 'default' => 1, 'label' => __( 'Google Consent Mode v2 (señales de consentimiento a Google)', 'dsmk-legal' ) ),
					'consent_mode_avanzado'  => array( 'type' => 'bool', 'default' => 0, 'label' => __( 'Consent Mode «avanzado» (cargar etiquetas de Google sin cookies antes del consentimiento)', 'dsmk-legal' ), 'help' => __( 'Desactivado = cumplimiento estricto: ninguna etiqueta de Google se carga sin consentimiento. Activarlo envía pings sin cookies a Google antes de consentir; la AEPD no lo ha avalado expresamente.', 'dsmk-legal' ) ),
					'ads_data_redaction'     => array( 'type' => 'bool', 'default' => 1, 'label' => __( 'Ocultar datos publicitarios sin consentimiento (ads_data_redaction)', 'dsmk-legal' ) ),
					'url_passthrough'        => array( 'type' => 'bool', 'default' => 0, 'label' => __( 'Pasar parámetros de clic por URL (url_passthrough)', 'dsmk-legal' ) ),
					'wp_consent_api'         => array( 'type' => 'bool', 'default' => 1, 'label' => __( 'Comunicar el consentimiento a otros plugins (WP Consent API)', 'dsmk-legal' ) ),
				),
			),
			'documentos'    => array(
				'title'  => __( 'Documentos legales', 'dsmk-legal' ),
				'intro'  => __( 'Activa los documentos que necesita la web. Las páginas se crean o asignan con los botones de la tabla de arriba; los textos se actualizan solos con los datos de los formularios.', 'dsmk-legal' ),
				'fields' => array(
					'doc_aviso'            => array( 'type' => 'bool', 'default' => 1, 'label' => __( 'Aviso legal', 'dsmk-legal' ) ),
					'doc_privacidad'       => array( 'type' => 'bool', 'default' => 1, 'label' => __( 'Política de privacidad', 'dsmk-legal' ) ),
					'doc_cookies'          => array( 'type' => 'bool', 'default' => 1, 'label' => __( 'Política de cookies', 'dsmk-legal' ) ),
					'doc_terminos'         => array( 'type' => 'bool', 'default' => 0, 'label' => __( 'Términos y condiciones del servicio', 'dsmk-legal' ), 'help' => __( 'Para webs que venden servicios (agencias, estudios, consultoras, profesionales). Se configura en la pestaña «Servicios».', 'dsmk-legal' ) ),
					'doc_condiciones'      => array( 'type' => 'bool', 'default' => 0, 'label' => __( 'Condiciones generales de venta (tienda online)', 'dsmk-legal' ), 'help' => __( 'Obligatorias en tiendas online. Se configuran en la pestaña «Tienda». Se asignan como página de términos de WooCommerce.', 'dsmk-legal' ) ),
					'doc_desistimiento'    => array( 'type' => 'bool', 'default' => 0, 'label' => __( 'Derecho de desistimiento (información y formulario modelo)', 'dsmk-legal' ) ),
					'paginas_auto'         => array( 'type' => 'bool', 'default' => 0, 'label' => __( 'Crear automáticamente las páginas que falten al guardar', 'dsmk-legal' ), 'help' => __( 'Desactivado (recomendado): las páginas se crean o asignan con los botones de arriba, después de revisar si la web ya tiene las suyas.', 'dsmk-legal' ) ),
					'pagina_aviso'         => array( 'type' => 'page', 'default' => 0, 'label' => __( 'Página: aviso legal', 'dsmk-legal' ) ),
					'pagina_privacidad'    => array( 'type' => 'page', 'default' => 0, 'label' => __( 'Página: política de privacidad', 'dsmk-legal' ) ),
					'pagina_cookies'       => array( 'type' => 'page', 'default' => 0, 'label' => __( 'Página: política de cookies', 'dsmk-legal' ) ),
					'pagina_terminos'      => array( 'type' => 'page', 'default' => 0, 'label' => __( 'Página: términos y condiciones', 'dsmk-legal' ) ),
					'pagina_condiciones'   => array( 'type' => 'page', 'default' => 0, 'label' => __( 'Página: condiciones de venta', 'dsmk-legal' ) ),
					'pagina_desistimiento' => array( 'type' => 'page', 'default' => 0, 'label' => __( 'Página: desistimiento', 'dsmk-legal' ), 'help' => __( 'Si usas el plugin de desistimiento de DSMK, elige aquí su página y marca «Página de otro plugin» en la pestaña Tienda.', 'dsmk-legal' ) ),
					'enlaces_footer'       => array( 'type' => 'bool', 'default' => 0, 'label' => __( 'Añadir automáticamente los enlaces legales al final de todas las páginas', 'dsmk-legal' ), 'help' => __( 'Si el pie está hecho con Elementor, mejor usa el shortcode [dsmk_enlaces_legales] en el pie.', 'dsmk-legal' ) ),
				),
			),
			'servicios_web' => array(
				'title'  => __( 'Términos y condiciones del servicio', 'dsmk-legal' ),
				'intro'  => __( 'Datos para el documento de términos y condiciones de servicios. Lo que dejes vacío se redacta con un texto genérico prudente.', 'dsmk-legal' ),
				'fields' => array(
					'serv_descripcion'         => array( 'type' => 'textarea', 'default' => '', 'label' => __( 'Servicios que se prestan', 'dsmk-legal' ), 'placeholder' => __( 'Ej.: diseño de identidad de marca, diseño gráfico, fotografía y vídeo corporativo…', 'dsmk-legal' ) ),
					'serv_clientes'            => array( 'type' => 'select', 'default' => 'empresas', 'label' => __( 'Tipo de clientes', 'dsmk-legal' ), 'options' => array( 'empresas' => __( 'Solo empresas y profesionales (B2B)', 'dsmk-legal' ), 'consumidores' => __( 'También particulares / consumidores', 'dsmk-legal' ) ) ),
					'serv_contratacion'        => array( 'type' => 'select', 'default' => 'presupuesto', 'label' => __( 'Cómo se contrata', 'dsmk-legal' ), 'options' => array( 'presupuesto' => __( 'Presupuesto aceptado por escrito', 'dsmk-legal' ), 'online' => __( 'Contratación y pago en la web', 'dsmk-legal' ), 'mixta' => __( 'Ambas', 'dsmk-legal' ) ) ),
					'serv_pago'                => array( 'type' => 'textarea', 'default' => '', 'label' => __( 'Precio y forma de pago', 'dsmk-legal' ), 'placeholder' => __( 'Ej.: 50 % al aceptar el presupuesto y 50 % a la entrega, por transferencia en 15 días desde la factura.', 'dsmk-legal' ) ),
					'serv_plazos'              => array( 'type' => 'textarea', 'default' => '', 'label' => __( 'Plazos de ejecución', 'dsmk-legal' ) ),
					'serv_revisiones'          => array( 'type' => 'text', 'default' => '', 'label' => __( 'Revisiones incluidas', 'dsmk-legal' ), 'placeholder' => __( 'Ej.: dos rondas de cambios', 'dsmk-legal' ) ),
					'serv_propiedad'           => array( 'type' => 'select', 'default' => 'cesion', 'label' => __( 'Propiedad intelectual de los trabajos', 'dsmk-legal' ), 'options' => array( 'cesion' => __( 'Cesión de derechos de explotación al cliente tras el pago', 'dsmk-legal' ), 'licencia' => __( 'El titular conserva los derechos y da licencia de uso', 'dsmk-legal' ) ) ),
					'serv_portfolio'           => array( 'type' => 'bool', 'default' => 1, 'label' => __( 'El titular puede mostrar los trabajos en su portfolio', 'dsmk-legal' ) ),
					'serv_cancelacion'         => array( 'type' => 'textarea', 'default' => '', 'label' => __( 'Cancelación por el cliente', 'dsmk-legal' ) ),
					'serv_responsabilidad_max' => array( 'type' => 'text', 'default' => '', 'label' => __( 'Límite de responsabilidad (solo B2B)', 'dsmk-legal' ), 'placeholder' => __( 'el importe abonado por el servicio afectado', 'dsmk-legal' ) ),
				),
			),
			'tienda'        => array(
				'title'  => __( 'Condiciones de venta', 'dsmk-legal' ),
				'intro'  => __( 'Datos para las condiciones generales de venta y el desistimiento de la tienda online.', 'dsmk-legal' ),
				'fields' => array(
					'tienda_productos'        => array( 'type' => 'text', 'default' => '', 'label' => __( 'Qué se vende', 'dsmk-legal' ), 'placeholder' => __( 'Ej.: láminas y obra gráfica original', 'dsmk-legal' ) ),
					'tienda_ambito'           => array( 'type' => 'text', 'default' => '', 'label' => __( 'Zonas de envío', 'dsmk-legal' ), 'placeholder' => __( 'Ej.: España peninsular y Baleares', 'dsmk-legal' ) ),
					'tienda_plazo_envio'      => array( 'type' => 'text', 'default' => '', 'label' => __( 'Plazo de entrega habitual', 'dsmk-legal' ), 'placeholder' => __( 'Ej.: 24-72 horas laborables', 'dsmk-legal' ) ),
					'tienda_coste_envio'      => array( 'type' => 'text', 'default' => '', 'label' => __( 'Gastos de envío', 'dsmk-legal' ), 'placeholder' => __( 'Ej.: Envío gratuito a partir de 50 €. Resto, 4,95 €.', 'dsmk-legal' ) ),
					'tienda_impuestos'        => array( 'type' => 'select', 'default' => 'iva_incluido', 'label' => __( 'Precios', 'dsmk-legal' ), 'options' => array( 'iva_incluido' => __( 'IVA incluido', 'dsmk-legal' ), 'iva_no_incluido' => __( 'IVA no incluido (venta a profesionales)', 'dsmk-legal' ) ) ),
					'tienda_pagos'            => array(
						'type'    => 'multicheck',
						'default' => array( 'tarjeta' ),
						'label'   => __( 'Formas de pago', 'dsmk-legal' ),
						'options' => array( 'tarjeta' => __( 'Tarjeta', 'dsmk-legal' ), 'paypal' => 'PayPal', 'bizum' => 'Bizum', 'transferencia' => __( 'Transferencia', 'dsmk-legal' ), 'contrareembolso' => __( 'Contra reembolso', 'dsmk-legal' ), 'wallet' => 'Apple Pay / Google Pay' ),
					),
					'tienda_desist_dias'      => array( 'type' => 'int', 'default' => 14, 'min' => 14, 'max' => 90, 'label' => __( 'Días para desistir', 'dsmk-legal' ), 'help' => __( 'Mínimo legal: 14 días naturales.', 'dsmk-legal' ) ),
					'tienda_devolucion_coste' => array( 'type' => 'select', 'default' => 'cliente', 'label' => __( 'Gastos de devolución', 'dsmk-legal' ), 'options' => array( 'cliente' => __( 'A cargo del cliente', 'dsmk-legal' ), 'tienda' => __( 'A cargo de la tienda', 'dsmk-legal' ) ) ),
					'tienda_excepciones'      => array(
						'type'    => 'multicheck',
						'default' => array(),
						'label'   => __( 'Productos sin derecho de desistimiento', 'dsmk-legal' ),
						'options' => array(
							'personalizados' => __( 'Personalizados o hechos a medida', 'dsmk-legal' ),
							'perecederos'    => __( 'Perecederos', 'dsmk-legal' ),
							'precintados'    => __( 'Precintados por higiene o salud', 'dsmk-legal' ),
							'grabaciones'    => __( 'Grabaciones o software precintados', 'dsmk-legal' ),
							'digitales'      => __( 'Contenido digital descargable', 'dsmk-legal' ),
							'mezclados'      => __( 'Mezclados con otros bienes tras la entrega', 'dsmk-legal' ),
						),
					),
					'tienda_atencion'         => array( 'type' => 'text', 'default' => '', 'label' => __( 'Atención al cliente (canal y horario)', 'dsmk-legal' ), 'placeholder' => __( 'Ej.: de lunes a viernes de 9 a 14 h', 'dsmk-legal' ) ),
				),
			),
			'desistimiento' => array(
				'title'  => __( 'Desistimiento', 'dsmk-legal' ),
				'intro'  => __( 'Desde el 19/06/2026 la Directiva (UE) 2023/2673 exige una función de desistimiento online en las tiendas. El plugin de desistimiento de DSMK se integra aquí: aporta el botón funcional y este hub, los textos.', 'dsmk-legal' ),
				'fields' => array(
					'desist_modo'      => array( 'type' => 'select', 'default' => 'interno', 'label' => __( 'Página de desistimiento', 'dsmk-legal' ), 'options' => array( 'interno' => __( 'La genera este plugin (información + formulario modelo)', 'dsmk-legal' ), 'externo' => __( 'Página de otro plugin (p. ej. el de desistimiento de DSMK)', 'dsmk-legal' ) ) ),
					'desist_email'     => array( 'type' => 'email', 'default' => '', 'label' => __( 'Email para desistimientos', 'dsmk-legal' ), 'help' => __( 'Vacío = email del titular.', 'dsmk-legal' ) ),
					'desist_direccion' => array( 'type' => 'text', 'default' => '', 'label' => __( 'Dirección para devoluciones', 'dsmk-legal' ), 'help' => __( 'Vacío = domicilio del titular.', 'dsmk-legal' ) ),
				),
			),
			'clausulas'     => array(
				'title'  => __( 'Textos adicionales', 'dsmk-legal' ),
				'intro'  => __( 'Cláusulas propias de este cliente. Se añaden al final de cada documento en un apartado «Otras condiciones».', 'dsmk-legal' ),
				'fields' => array(
					'extra_aviso'         => array( 'type' => 'html', 'default' => '', 'label' => __( 'Aviso legal', 'dsmk-legal' ) ),
					'extra_privacidad'    => array( 'type' => 'html', 'default' => '', 'label' => __( 'Política de privacidad', 'dsmk-legal' ) ),
					'extra_cookies'       => array( 'type' => 'html', 'default' => '', 'label' => __( 'Política de cookies', 'dsmk-legal' ) ),
					'extra_terminos'      => array( 'type' => 'html', 'default' => '', 'label' => __( 'Términos y condiciones del servicio', 'dsmk-legal' ) ),
					'extra_condiciones'   => array( 'type' => 'html', 'default' => '', 'label' => __( 'Condiciones de venta', 'dsmk-legal' ) ),
					'extra_desistimiento' => array( 'type' => 'html', 'default' => '', 'label' => __( 'Desistimiento', 'dsmk-legal' ) ),
				),
			),
			'servicios'     => array(
				'title'  => __( 'Escaneo', 'dsmk-legal' ),
				'fields' => array(
					'escaneo_semanal' => array( 'type' => 'bool', 'default' => 1, 'label' => __( 'Escanear la web automáticamente cada semana', 'dsmk-legal' ) ),
					'bump_nuevos'     => array( 'type' => 'bool', 'default' => 1, 'label' => __( 'Volver a pedir el consentimiento cuando aparezcan servicios nuevos', 'dsmk-legal' ) ),
					'email_avisos'    => array( 'type' => 'email', 'default' => '', 'label' => __( 'Email para avisos de nuevos servicios o cookies sin clasificar', 'dsmk-legal' ), 'help' => __( 'Vacío = email del administrador.', 'dsmk-legal' ) ),
				),
			),
			'registro'      => array(
				'title'  => __( 'Registro de consentimientos', 'dsmk-legal' ),
				'fields' => array(
					'log_activo' => array( 'type' => 'bool', 'default' => 1, 'label' => __( 'Guardar prueba de cada consentimiento', 'dsmk-legal' ), 'help' => __( 'Se guarda: ID anónimo, fecha, elección, versión del banner, IP anonimizada y huella del navegador (hash). Sin datos identificativos.', 'dsmk-legal' ) ),
					'log_meses'  => array( 'type' => 'int', 'default' => 36, 'min' => 12, 'max' => 72, 'label' => __( 'Conservar registros (meses)', 'dsmk-legal' ), 'help' => __( '36 meses cubre el plazo de prescripción de infracciones muy graves de la LSSI (3 años).', 'dsmk-legal' ) ),
				),
			),
			'avanzado'      => array(
				'title'  => __( 'Avanzado', 'dsmk-legal' ),
				'fields' => array(
					'patrones_extra' => array( 'type' => 'textarea', 'default' => '', 'label' => __( 'Bloqueos adicionales', 'dsmk-legal' ), 'help' => __( 'Una regla por línea: «texto-de-la-url | categoría | nombre del servicio». Categorías: preferences, analytics, marketing. Ej.: «widget.ejemplo.com | marketing | Widget Ejemplo».', 'dsmk-legal' ) ),
					'borrar_datos'   => array( 'type' => 'bool', 'default' => 0, 'label' => __( 'Borrar todos los datos y registros al desinstalar el plugin', 'dsmk-legal' ) ),
				),
			),
		);

		/**
		 * Permite ampliar el esquema desde otros plugins o el tema.
		 *
		 * @since 1.0.0
		 * @param array $schema Esquema completo.
		 */
		return apply_filters( 'dsmkc_settings_schema', $schema );
	}

	/**
	 * Devuelve la lista plana de campos (clave => definición).
	 *
	 * @since  1.0.0
	 * @return array<string, array>
	 */
	public static function fields() {
		$out = array();
		foreach ( self::schema() as $section ) {
			foreach ( $section['fields'] as $key => $field ) {
				$out[ $key ] = $field;
			}
		}
		return $out;
	}

	/**
	 * Valores por defecto de todos los campos.
	 *
	 * @since  1.0.0
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		$out = array();
		foreach ( self::fields() as $key => $field ) {
			$out[ $key ] = $field['default'];
		}
		return $out;
	}

	/**
	 * Configuración completa fusionada con los valores por defecto.
	 *
	 * @since  1.0.0
	 * @return array<string, mixed>
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * Devuelve un valor de configuración.
	 *
	 * @since  1.0.0
	 * @param  string $key      Clave del campo.
	 * @param  mixed  $fallback Valor si no existe.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Sanea y guarda un conjunto de valores (solo las claves recibidas).
	 *
	 * @since  1.0.0
	 * @param  array $input       Valores en bruto (p. ej. $_POST['dsmkc']).
	 * @param  array $only_fields Limita el guardado a estas claves (pestaña actual).
	 * @return array Configuración resultante.
	 */
	public static function save( array $input, array $only_fields ) {
		$fields  = self::fields();
		$current = self::all();

		foreach ( $only_fields as $key ) {
			if ( ! isset( $fields[ $key ] ) ) {
				continue;
			}
			// Los checkbox no enviados equivalen a 0 / lista vacía.
			$raw             = array_key_exists( $key, $input ) ? $input[ $key ] : null;
			$current[ $key ] = self::sanitize_field( $raw, $fields[ $key ] );
		}

		update_option( self::OPTION, $current, true );
		update_option( 'dsmkc_settings_updated', time(), false ); // Fecha de «última actualización» de los textos.
		self::$cache = null;

		/**
		 * Se dispara tras guardar los ajustes.
		 *
		 * @since 1.0.0
		 * @param array $current Configuración guardada.
		 */
		do_action( 'dsmkc_settings_saved', $current );

		return self::all();
	}

	/**
	 * Sustituye toda la configuración (importación). Sanea cada campo.
	 *
	 * @since  1.0.0
	 * @param  array $input Configuración importada.
	 * @return void
	 */
	public static function import( array $input ) {
		self::save( $input, array_keys( self::fields() ) );
	}

	/**
	 * Sanea un valor según la definición de su campo.
	 *
	 * @since  1.0.0
	 * @param  mixed $value Valor en bruto (puede ser null si no se envió).
	 * @param  array $field Definición del campo.
	 * @return mixed Valor saneado.
	 */
	public static function sanitize_field( $value, array $field ) {
		$value = is_string( $value ) ? wp_unslash( $value ) : $value;

		switch ( $field['type'] ) {
			case 'bool':
				return empty( $value ) ? 0 : 1;

			case 'int':
				$int = is_numeric( $value ) ? (int) $value : (int) $field['default'];
				if ( isset( $field['min'] ) ) {
					$int = max( (int) $field['min'], $int );
				}
				if ( isset( $field['max'] ) ) {
					$int = min( (int) $field['max'], $int );
				}
				return $int;

			case 'page':
				$id = absint( $value );
				return ( $id && 'page' === get_post_type( $id ) ) ? $id : 0;

			case 'email':
				return sanitize_email( (string) $value );

			case 'url':
				return esc_url_raw( (string) $value );

			case 'color':
				$color = sanitize_hex_color( (string) $value );
				return $color ? $color : $field['default'];

			case 'select':
				$value = (string) $value;
				return array_key_exists( $value, $field['options'] ) ? $value : $field['default'];

			case 'multicheck':
				$value = is_array( $value ) ? array_map( 'sanitize_key', $value ) : array();
				return array_values( array_intersect( $value, array_keys( $field['options'] ) ) );

			case 'html':
				return wp_kses_post( (string) $value );

			case 'textarea':
				return sanitize_textarea_field( (string) $value );

			case 'css':
				return self::sanitize_css( (string) $value );

			case 'text':
			default:
				$text = sanitize_text_field( (string) $value );
				if ( '' !== $text && ! empty( $field['pattern'] ) ) {
					// Normaliza mayúsculas según el formato esperado del identificador.
					$text = ( false !== strpos( $field['pattern'], 'a-z' ) ) ? strtolower( $text ) : strtoupper( $text );
					if ( ! preg_match( $field['pattern'], $text ) ) {
						return '';
					}
				}
				return $text;
		}
	}

	/**
	 * Limpia CSS introducido por el usuario.
	 *
	 * Seguridad: elimina etiquetas (evita cerrar <style>), expresiones antiguas
	 * de IE, url(javascript:...) y @import (carga de recursos externos).
	 *
	 * @since  1.0.0
	 * @param  string $css CSS en bruto.
	 * @return string CSS saneado.
	 */
	public static function sanitize_css( $css ) {
		$css = wp_strip_all_tags( $css );
		$css = preg_replace( '#(expression\s*\(|javascript\s*:|behavior\s*:|@import)#i', '', $css );
		return trim( $css );
	}

	/* =====================================================================
	 * Estado interno (no editable directamente por el usuario)
	 * ===================================================================== */

	/**
	 * Estado interno con valores por defecto.
	 *
	 * Claves:
	 *   policy_version (int)   Versión del consentimiento; si cambia se vuelve a preguntar.
	 *   scan_token     (string) Secreto para las peticiones de escaneo.
	 *   detected       (array) id_servicio => [ 'first' => ts, 'last' => ts, 'via' => string ].
	 *   unknown        (array) nombre => [ 'type' => cookie|storage|domain, 'first' => ts, 'last' => ts, 'via' => string ].
	 *   manual         (array) Cookies añadidas o clasificadas a mano.
	 *   overrides      (array) id_servicio => categoría | 'excluded'.
	 *   ignored        (array) Nombres de cookies desconocidas ignoradas.
	 *   last_scan      (int)   Marca de tiempo del último escaneo.
	 *   last_scan_log  (array) Resumen del último escaneo.
	 *   services_hash  (string) Huella de los servicios no necesarios activos.
	 *   banner_history (array) hash => [ 'date' => ts, 'texts' => array ] (prueba de la versión mostrada).
	 *   updated        (int)   Última modificación de la política.
	 *
	 * @since  1.0.0
	 * @return array<string, mixed>
	 */
	public static function data() {
		if ( null === self::$data_cache ) {
			$saved            = get_option( self::DATA, array() );
			self::$data_cache = wp_parse_args(
				is_array( $saved ) ? $saved : array(),
				array(
					'policy_version' => 1,
					'scan_token'     => '',
					'detected'       => array(),
					'unknown'        => array(),
					'manual'         => array(),
					'overrides'      => array(),
					'ignored'        => array(),
					'last_scan'      => 0,
					'last_scan_log'  => array(),
					'services_hash'  => '',
					'banner_history' => array(),
					'updated'        => 0,
				)
			);
		}
		return self::$data_cache;
	}

	/**
	 * Actualiza claves del estado interno.
	 *
	 * @since  1.0.0
	 * @param  array $changes Claves a sustituir.
	 * @return array Estado resultante.
	 */
	public static function update_data( array $changes ) {
		$data = array_merge( self::data(), $changes );
		update_option( self::DATA, $data, false );
		self::$data_cache = $data;
		return $data;
	}

	/**
	 * Token secreto para que el escáner pueda ver la web sin bloqueos.
	 *
	 * Seguridad: 32 caracteres aleatorios; solo se compara con hash_equals().
	 *
	 * @since  1.0.0
	 * @return string
	 */
	public static function scan_token() {
		$data = self::data();
		if ( empty( $data['scan_token'] ) ) {
			$data = self::update_data( array( 'scan_token' => wp_generate_password( 32, false, false ) ) );
		}
		return $data['scan_token'];
	}

	/**
	 * Incrementa la versión de la política: todos los visitantes vuelven a ver el banner.
	 *
	 * @since  1.0.0
	 * @return int Nueva versión.
	 */
	public static function bump_version() {
		$version = (int) self::data()['policy_version'] + 1;
		self::update_data( array( 'policy_version' => $version, 'updated' => time() ) );
		return $version;
	}

	/**
	 * Limpia las cachés en memoria (útil en pruebas).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function flush() {
		self::$cache      = null;
		self::$data_cache = null;
	}
}
