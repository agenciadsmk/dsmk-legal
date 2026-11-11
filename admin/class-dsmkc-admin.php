<?php
/**
 * DSMK Legal — Panel de administración.
 *
 * @package   DSMK_Legal
 * @file      admin/class-dsmkc-admin.php
 * @since     1.0.0
 * @author    DSMK Agencia Digital <hola@dsmk.es>
 * @license   GPL-2.0-or-later
 *
 * Responsabilidad:
 *   Página «DSMK Legal» con pestañas: Resumen, Titular, Banner y estilo,
 *   Textos, Servicios y cookies, Integraciones, Páginas legales, Registro,
 *   Informe y Avanzado. Los formularios se generan a partir del esquema de
 *   DSMKC_Settings. Acciones: guardar, crear páginas, escanear (servidor y
 *   navegador), clasificar cookies, corregir categorías, exportar CSV,
 *   informe imprimible, importar/exportar ajustes y volver a pedir consentimiento.
 *
 * Seguridad (todas las acciones):
 *   - Capacidad requerida: manage_options (filtrable con dsmkc_capability).
 *   - Nonce por acción (check_admin_referer / check_ajax_referer).
 *   - Entradas saneadas; salidas escapadas.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Administración.
 *
 * @since 1.0.0
 */
final class DSMKC_Admin {

	/** @var string Slug de la página. */
	const PAGE = 'dsmk-legal';

	/**
	 * Pestañas: clave => [título, secciones del esquema que contiene].
	 *
	 * @since  1.0.0
	 * @return array<string, array{0:string, 1:string[]}>
	 */
	private static function tabs() {
		return array(
			'inicio'            => array( __( 'Resumen', 'dsmk-legal' ), array() ),
			'titular'           => array( __( 'Titular', 'dsmk-legal' ), array( 'titular' ) ),
			'documentos'        => array( __( 'Documentos', 'dsmk-legal' ), array( 'documentos' ) ),
			'servicios_web'     => array( __( 'Servicios', 'dsmk-legal' ), array( 'servicios_web' ) ),
			'tienda'            => array( __( 'Tienda', 'dsmk-legal' ), array( 'tienda', 'desistimiento' ) ),
			'clausulas'         => array( __( 'Textos adicionales', 'dsmk-legal' ), array( 'clausulas' ) ),
			'cookies_banner'    => array( __( 'Cookies: banner', 'dsmk-legal' ), array( 'banner', 'estilo' ) ),
			'cookies_textos'    => array( __( 'Cookies: categorías', 'dsmk-legal' ), array( 'categorias' ) ),
			'cookies_servicios' => array( __( 'Cookies: servicios', 'dsmk-legal' ), array( 'servicios' ) ),
			'integraciones'     => array( __( 'Integraciones', 'dsmk-legal' ), array( 'integraciones' ) ),
			'registro'          => array( __( 'Registro', 'dsmk-legal' ), array( 'registro' ) ),
			'informe'           => array( __( 'Auditoría', 'dsmk-legal' ), array() ),
			// En multisite los ajustes de actualización no se muestran: mandan las constantes de la red (DSMKC_Updater::settings_allowed()).
			'herramientas'      => array( __( 'Herramientas', 'dsmk-legal' ), DSMKC_Updater::settings_allowed() ? array( 'actualizaciones', 'avanzado' ) : array( 'avanzado' ) ),
		);
	}

	/**
	 * Capacidad necesaria.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	public static function cap() {
		return (string) apply_filters( 'dsmkc_capability', 'manage_options' );
	}

	/**
	 * Registra hooks.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DSMKC_FILE ), array( $this, 'action_links' ) );
		add_action( 'admin_notices', array( $this, 'setup_notice' ) );

		foreach ( array( 'save', 'create_pages', 'scan', 'bump', 'export_csv', 'report', 'override', 'classify', 'ignore', 'manual_delete', 'export_settings', 'import_settings', 'ai_brief', 'detect_apply', 'create_page', 'assign_page', 'audit', 'check_update' ) as $action ) {
			add_action( 'admin_post_dsmkc_' . $action, array( $this, 'handle_' . $action ) );
		}
		add_action( 'wp_ajax_dsmkc_browser_scan', array( $this, 'ajax_browser_scan' ) );
	}

	/**
	 * Menú.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function menu() {
		add_menu_page(
			__( 'DSMK Legal', 'dsmk-legal' ),
			__( 'DSMK Legal', 'dsmk-legal' ),
			self::cap(),
			self::PAGE,
			array( $this, 'render' ),
			'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#a7aaad" d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5Zm-3.5 7A1.5 1.5 0 1 1 7 10.5 1.5 1.5 0 0 1 8.5 9Zm-1 6A1.5 1.5 0 1 1 6 16.5 1.5 1.5 0 0 1 7.5 15Zm5.5 2a1 1 0 1 1-1 1 1 1 0 0 1 1-1Zm4-3a1.5 1.5 0 1 1-1.5 1.5A1.5 1.5 0 0 1 17 14Z"/></svg>' ),
			81
		);
	}

	/**
	 * Enlace «Ajustes» en la lista de plugins.
	 *
	 * @since  1.0.0
	 * @param  array $links Enlaces.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Ajustes', 'dsmk-legal' ) . '</a>' );
		return $links;
	}

	/**
	 * Aviso si faltan datos básicos.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function setup_notice() {
		if ( ! current_user_can( self::cap() ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, self::PAGE ) ) {
			return;
		}
		if ( '' !== trim( (string) DSMKC_Settings::get( 'titular_nombre' ) ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>DSMK Legal:</strong> ' . esc_html__( 'el banner ya bloquea las cookies, pero faltan los datos del titular para generar los textos legales.', 'dsmk-legal' ) . ' <a href="' . esc_url( self::url( 'titular' ) ) . '">' . esc_html__( 'Completar ahora', 'dsmk-legal' ) . '</a></p></div>';
	}

	/**
	 * URL de una pestaña.
	 *
	 * @since  1.0.0
	 * @param  string $tab  Pestaña.
	 * @param  array  $args Parámetros extra.
	 * @return string
	 */
	public static function url( $tab = 'inicio', array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Recursos del panel.
	 *
	 * @since  1.0.0
	 * @param  string $hook Pantalla.
	 * @return void
	 */
	public function assets( $hook ) {
		if ( 'toplevel_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( 'dsmkc-banner', DSMKC_URL . 'assets/css/dsmkc-banner.css', array(), DSMKC_VERSION );
		wp_add_inline_style( 'dsmkc-banner', DSMKC_Frontend::css_vars() . self::elementor_vars_css() );
		wp_enqueue_style( 'dsmkc-admin', DSMKC_URL . 'assets/css/dsmkc-admin.css', array( 'dsmkc-banner' ), DSMKC_VERSION );
		wp_enqueue_script( 'dsmkc-admin', DSMKC_URL . 'assets/js/dsmkc-admin.js', array( 'jquery', 'wp-color-picker' ), DSMKC_VERSION, true );
		wp_localize_script(
			'dsmkc-admin',
			'dsmkcAdmin',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'dsmkc_browser_scan' ),
				'token' => wp_create_nonce( 'dsmkc_scan_view' ), // Nonce (2.2.0): sin la sesión del administrador no sirve.
				'urls'  => DSMKC_Scanner::urls(),
				'host'  => wp_parse_url( home_url(), PHP_URL_HOST ),
				't'     => array(
					'scanning' => __( 'Escaneando', 'dsmk-legal' ),
					'done'     => __( 'Escaneo completado. Recargando…', 'dsmk-legal' ),
					'error'    => __( 'No se pudo leer la web desde el navegador (¿dominio distinto o protección contra iframes?). Usa el escaneo de servidor.', 'dsmk-legal' ),
					'copied'   => __( 'Copiado', 'dsmk-legal' ),
					'auditing' => __( 'Escaneo del navegador terminado. Ejecutando la auditoría de servidor (puede tardar hasta un minuto)…', 'dsmk-legal' ),
				),
			)
		);
	}

	/**
	 * Variables CSS de los colores y tipografía globales de Elementor (kit
	 * activo) para que la vista previa del admin se vea como en la web.
	 *
	 * @since  1.0.0
	 * @return string CSS.
	 */
	public static function elementor_vars_css() {
		$kit = (int) get_option( 'elementor_active_kit' );
		if ( ! $kit ) {
			return '';
		}
		$settings = get_post_meta( $kit, '_elementor_page_settings', true );
		if ( ! is_array( $settings ) ) {
			return '';
		}
		$css = '';
		foreach ( (array) ( isset( $settings['system_colors'] ) ? $settings['system_colors'] : array() ) as $color ) {
			if ( ! empty( $color['_id'] ) && ! empty( $color['color'] ) ) {
				$hex = sanitize_hex_color( $color['color'] );
				if ( $hex ) {
					$css .= '--e-global-color-' . sanitize_key( $color['_id'] ) . ':' . $hex . ';';
				}
			}
		}
		foreach ( (array) ( isset( $settings['system_typography'] ) ? $settings['system_typography'] : array() ) as $font ) {
			if ( ! empty( $font['_id'] ) && ! empty( $font['typography_font_family'] ) ) {
				$css .= '--e-global-typography-' . sanitize_key( $font['_id'] ) . '-font-family:"' . esc_attr( preg_replace( '/[^a-zA-Z0-9 \-]/', '', $font['typography_font_family'] ) ) . '";';
			}
		}
		return $css ? '.dsmkc-preview{' . $css . '}' : '';
	}

	/* =====================================================================
	 * Renderizado
	 * ===================================================================== */

	/**
	 * Página principal.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'No tienes permisos suficientes.', 'dsmk-legal' ) );
		}
		$tabs = self::tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Solo navegación.
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'inicio';
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'inicio';
		?>
<div class="wrap dsmkc-admin">
	<h1 class="dsmkc-admin-title"><?php esc_html_e( 'DSMK Legal', 'dsmk-legal' ); ?> <span class="dsmkc-version">v<?php echo esc_html( DSMKC_VERSION ); ?></span></h1>
	<?php $this->notices(); ?>
	<nav class="nav-tab-wrapper">
		<?php foreach ( $tabs as $key => $def ) : ?>
			<a href="<?php echo esc_url( self::url( $key ) ); ?>" class="nav-tab <?php echo $key === $tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $def[0] ); ?></a>
		<?php endforeach; ?>
	</nav>
	<div class="dsmkc-tab">
		<?php
		switch ( $tab ) {
			case 'inicio':
				$this->tab_inicio();
				break;
			case 'cookies_servicios':
				$this->tab_servicios();
				break;
			case 'documentos':
				$this->tab_documentos();
				break;
			case 'registro':
				$this->tab_registro();
				break;
			case 'informe':
				$this->tab_informe();
				break;
			case 'herramientas':
				$this->tab_herramientas();
				$this->settings_form( $tab, $tabs[ $tab ][1] );
				break;
			case 'cookies_banner':
				echo '<div class="dsmkc-two-cols"><div>';
				$this->settings_form( $tab, $tabs[ $tab ][1] );
				echo '</div><div>';
				$this->preview();
				echo '</div></div>';
				break;
			default:
				$this->settings_form( $tab, $tabs[ $tab ][1] );
		}
		?>
	</div>
</div>
		<?php
	}

	/**
	 * Avisos tras una acción (parámetro dsmkc_msg).
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Solo mensajes informativos.
		if ( empty( $_GET['dsmkc_msg'] ) ) {
			return;
		}
		$msg      = sanitize_key( wp_unslash( $_GET['dsmkc_msg'] ) );
		// phpcs:enable
		// El detalle llega por transient del usuario (2.2.0), no por la URL: un
		// enlace manipulado no puede poner texto engañoso delante del administrador.
		$key   = 'dsmkc_notice_' . get_current_user_id();
		$extra = (string) get_transient( $key );
		delete_transient( $key );
		$messages = array(
			'saved'      => __( 'Ajustes guardados. Se han vaciado las cachés de página compatibles.', 'dsmk-legal' ),
			'pages'      => __( 'Hecho.', 'dsmk-legal' ),
			'assigned'   => __( 'Página asignada al documento.', 'dsmk-legal' ),
			'audited'    => __( 'Auditoría completada y guardada en el histórico.', 'dsmk-legal' ),
			'scanned'    => __( 'Escaneo completado.', 'dsmk-legal' ),
			'bumped'     => __( 'Hecho: todos los visitantes verán de nuevo el banner.', 'dsmk-legal' ),
			'classified' => __( 'Cookie clasificada y añadida a la política.', 'dsmk-legal' ),
			'ignored'    => __( 'Elemento ignorado.', 'dsmk-legal' ),
			'override'   => __( 'Servicio actualizado.', 'dsmk-legal' ),
			'deleted'    => __( 'Cookie eliminada de la política.', 'dsmk-legal' ),
			'imported'   => __( 'Ajustes importados.', 'dsmk-legal' ),
			'updates'    => __( 'Comprobación de actualizaciones hecha.', 'dsmk-legal' ),
			'error'      => __( 'No se pudo completar la acción.', 'dsmk-legal' ),
		);
		if ( isset( $messages[ $msg ] ) ) {
			$class = 'error' === $msg ? 'notice-error' : 'notice-success';
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $messages[ $msg ] . ( $extra ? ' ' . $extra : '' ) ) . '</p></div>';
		}
	}

	/**
	 * Formulario genérico a partir del esquema.
	 *
	 * @since  1.0.0
	 * @param  string   $tab      Pestaña.
	 * @param  string[] $sections Secciones del esquema.
	 * @return void
	 */
	private function settings_form( $tab, array $sections ) {
		$schema = DSMKC_Settings::schema();
		$values = DSMKC_Settings::all();
		?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dsmkc-form">
	<input type="hidden" name="action" value="dsmkc_save">
	<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
	<?php wp_nonce_field( 'dsmkc_save' ); ?>
	<?php foreach ( $sections as $section_key ) : ?>
		<?php
		if ( ! isset( $schema[ $section_key ] ) ) {
			continue;
		}
		$section = $schema[ $section_key ];
		?>
		<div class="dsmkc-card" data-section="<?php echo esc_attr( $section_key ); ?>">
			<h2><?php echo esc_html( $section['title'] ); ?></h2>
			<?php if ( ! empty( $section['intro'] ) ) : ?><p class="description"><?php echo esc_html( $section['intro'] ); ?></p><?php endif; ?>
			<table class="form-table" role="presentation">
				<?php foreach ( $section['fields'] as $key => $field ) : ?>
					<tr class="dsmkc-field dsmkc-field--<?php echo esc_attr( $field['type'] ); ?>" data-field="<?php echo esc_attr( $key ); ?>">
						<th scope="row"><label for="dsmkc-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['label'] ); ?><?php echo ! empty( $field['required'] ) ? ' <span class="dsmkc-req">*</span>' : ''; ?></label></th>
						<td>
							<?php $this->field( $key, $field, $values[ $key ] ); ?>
							<?php if ( ! empty( $field['help'] ) ) : ?><p class="description"><?php echo esc_html( $field['help'] ); ?></p><?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
		</div>
	<?php endforeach; ?>
	<?php submit_button( __( 'Guardar cambios', 'dsmk-legal' ) ); ?>
</form>
		<?php
	}

	/**
	 * Un campo del formulario.
	 *
	 * @since  1.0.0
	 * @param  string $key   Clave.
	 * @param  array  $field Definición.
	 * @param  mixed  $value Valor actual.
	 * @return void
	 */
	private function field( $key, array $field, $value ) {
		$id   = 'dsmkc-' . $key;
		$name = 'dsmkc[' . $key . ']';
		$ph   = isset( $field['placeholder'] ) ? $field['placeholder'] : '';
		switch ( $field['type'] ) {
			case 'bool':
				printf( '<label><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s> %4$s</label>', esc_attr( $id ), esc_attr( $name ), checked( (int) $value, 1, false ), esc_html__( 'Activado', 'dsmk-legal' ) );
				break;
			case 'int':
				printf( '<input type="number" class="small-text" id="%1$s" name="%2$s" value="%3$d" min="%4$d" max="%5$d">', esc_attr( $id ), esc_attr( $name ), (int) $value, isset( $field['min'] ) ? (int) $field['min'] : 0, isset( $field['max'] ) ? (int) $field['max'] : 9999 );
				break;
			case 'color':
				printf( '<input type="text" class="dsmkc-color" id="%1$s" name="%2$s" value="%3$s" data-default-color="%4$s" data-var="%5$s">', esc_attr( $id ), esc_attr( $name ), esc_attr( $value ), esc_attr( $field['default'] ), esc_attr( $key ) );
				break;
			case 'select':
				printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( $field['options'] as $opt => $label ) {
					printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $opt ), selected( $value, $opt, false ), esc_html( $label ) );
				}
				echo '</select>';
				break;
			case 'multicheck':
				echo '<fieldset>';
				foreach ( $field['options'] as $opt => $label ) {
					printf( '<label class="dsmkc-check"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s> %4$s</label>', esc_attr( $name ), esc_attr( $opt ), checked( in_array( $opt, (array) $value, true ), true, false ), esc_html( $label ) );
				}
				echo '</fieldset>';
				break;
			case 'page':
				wp_dropdown_pages(
					array(
						'name'              => esc_attr( $name ),
						'id'                => esc_attr( $id ),
						'selected'          => (int) $value,
						'show_option_none'  => esc_html__( '— Sin asignar —', 'dsmk-legal' ),
						'option_none_value' => '0',
						'post_status'       => array( 'publish', 'draft', 'private' ),
					)
				);
				$url = (int) $value ? get_permalink( (int) $value ) : '';
				if ( $url ) {
					printf( ' <a href="%s" target="_blank">%s</a>', esc_url( $url ), esc_html__( 'Ver', 'dsmk-legal' ) );
				}
				break;
			case 'html':
			case 'textarea':
			case 'css':
				printf( '<textarea class="large-text%5$s" rows="%4$d" id="%1$s" name="%2$s" placeholder="%6$s">%3$s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( (string) $value ), 'css' === $field['type'] ? 8 : 4, 'css' === $field['type'] ? ' code' : '', esc_attr( $ph ) );
				break;
			case 'email':
				printf( '<input type="email" class="regular-text" id="%1$s" name="%2$s" value="%3$s" placeholder="%4$s">', esc_attr( $id ), esc_attr( $name ), esc_attr( $value ), esc_attr( $ph ) );
				break;
			default:
				// Campos «secret» (2.3.0): tipo password y sin autocompletar del navegador.
				printf(
					'<input type="%5$s" class="regular-text" id="%1$s" name="%2$s" value="%3$s" placeholder="%4$s"%6$s>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value ),
					esc_attr( $ph ),
					empty( $field['secret'] ) ? 'text' : 'password',
					empty( $field['secret'] ) ? '' : ' autocomplete="new-password" spellcheck="false"'
				);
		}
	}

	/**
	 * Vista previa del banner y del panel.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function preview() {
		?>
<div class="dsmkc-card dsmkc-sticky">
	<h2><?php esc_html_e( 'Vista previa', 'dsmk-legal' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Guarda para actualizar textos. Los colores se actualizan al momento. En modo «Heredar» se usan los colores globales de Elementor del kit activo.', 'dsmk-legal' ); ?></p>
	<div class="dsmkc-preview">
		<?php echo DSMKC_Frontend::banner_markup( true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escapado en banner_markup(). ?>
	</div>
	<p><button type="button" class="button" id="dsmkc-preview-panel"><?php esc_html_e( 'Ver / ocultar el panel de configuración', 'dsmk-legal' ); ?></button></p>
</div>
		<?php
	}

	/**
	 * Pestaña Resumen.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function tab_inicio() {
		$checks  = DSMKC_Report::checks( false );
		$summary = DSMKC_Report::summary( $checks );
		$data    = DSMKC_Settings::data();
		$stats   = DSMKC_Consent_Log::stats( 30 );
		?>
<div class="dsmkc-grid">
	<div class="dsmkc-card">
		<h2><?php esc_html_e( 'Puesta en marcha', 'dsmk-legal' ); ?></h2>
		<ol class="dsmkc-steps">
			<li><?php printf( wp_kses_post( __( 'Rellena los <a href="%s">datos del titular</a>.', 'dsmk-legal' ) ), esc_url( self::url( 'titular' ) ) ); ?></li>
			<li><?php printf( wp_kses_post( __( 'Pulsa «Escanear la web» en <a href="%s">Servicios y cookies</a> (el plugin ya bloquea todo lo conocido desde el primer minuto).', 'dsmk-legal' ) ), esc_url( self::url( 'cookies_servicios' ) ) ); ?></li>
			<li><?php printf( wp_kses_post( __( 'En <a href="%s">Documentos</a> activa los que necesita la web y, para cada uno, usa la página que ya tenga o crea una nueva con su botón.', 'dsmk-legal' ) ), esc_url( self::url( 'documentos' ) ) ); ?></li>
			<li><?php printf( wp_kses_post( __( 'Atajo: en <a href="%s">Herramientas</a> puedes detectar datos automáticamente, descargar el briefing para la IA y pegar el JSON que te devuelva.', 'dsmk-legal' ) ), esc_url( self::url( 'herramientas' ) ) ); ?></li>
			<li><?php printf( wp_kses_post( __( 'Ajusta el <a href="%s">aspecto del banner</a> si lo necesitas.', 'dsmk-legal' ) ), esc_url( self::url( 'cookies_banner' ) ) ); ?></li>
			<li><?php printf( wp_kses_post( __( 'Ejecuta la <a href="%s">auditoría completa</a> y descarga el informe técnico.', 'dsmk-legal' ) ), esc_url( self::url( 'informe' ) ) ); ?></li>
		</ol>
	</div>
	<div class="dsmkc-card">
		<h2><?php esc_html_e( 'Estado', 'dsmk-legal' ); ?></h2>
		<p class="dsmkc-big"><span class="dsmkc-ok"><?php echo (int) $summary['ok']; ?> ✔</span> <span class="dsmkc-warn"><?php echo (int) $summary['warn']; ?> !</span> <span class="dsmkc-fail"><?php echo (int) $summary['fail']; ?> ✘</span></p>
		<ul class="dsmkc-checklist">
			<?php foreach ( $checks as $check ) : ?>
				<li class="dsmkc-<?php echo esc_attr( $check['status'] ); ?>"><strong><?php echo esc_html( $check['label'] ); ?></strong><br><span><?php echo esc_html( wp_strip_all_tags( $check['detail'] ) ); ?></span></li>
			<?php endforeach; ?>
		</ul>
	</div>
	<div class="dsmkc-card">
		<h2><?php esc_html_e( 'Datos', 'dsmk-legal' ); ?></h2>
		<ul>
			<li><?php echo esc_html( sprintf( __( 'Servicios detectados: %d', 'dsmk-legal' ), count( $data['detected'] ) ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'Cookies documentadas: %d', 'dsmk-legal' ), count( DSMKC_Services::cookie_rows() ) ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'Último escaneo: %s', 'dsmk-legal' ), $data['last_scan'] ? wp_date( 'd/m/Y H:i', $data['last_scan'] ) : __( 'nunca', 'dsmk-legal' ) ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'Versión de la política: %d', 'dsmk-legal' ), $data['policy_version'] ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'Consentimientos (30 días): %1$d — aceptan todo %2$d · rechazan todo %3$d · personalizado %4$d', 'dsmk-legal' ), $stats['total'], $stats['accept'], $stats['reject'], $stats['custom'] ) ); ?></li>
		</ul>
		<?php $last = DSMKC_Audit::get(); ?>
		<p><?php echo esc_html( $last ? sprintf( __( 'Última auditoría: %1$s · %2$d/100', 'dsmk-legal' ), wp_date( 'd/m/Y H:i', $last['date'] ), $last['score'] ) : __( 'Sin auditorías todavía.', 'dsmk-legal' ) ); ?></p>
		<p><a class="button button-primary" href="<?php echo esc_url( self::url( 'informe' ) ); ?>"><?php esc_html_e( 'Ir a la auditoría', 'dsmk-legal' ); ?></a></p>
	</div>
</div>
		<?php
	}

	/**
	 * Pestaña Servicios y cookies.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function tab_servicios() {
		$data      = DSMKC_Settings::data();
		$catalogue = DSMKC_Services::catalogue();
		$labels    = DSMKC_Services::category_labels();
		$log       = (array) $data['last_scan_log'];
		?>
<div class="dsmkc-card">
	<h2><?php esc_html_e( 'Escanear la web', 'dsmk-legal' ); ?></h2>
	<p class="description"><?php esc_html_e( 'El escaneo completo usa tu navegador (detecta cookies creadas por JavaScript y servicios cargados por Google Tag Manager) y el servidor (cabeceras y HTML). Tarda unos segundos por página.', 'dsmk-legal' ); ?></p>
	<p>
		<button type="button" class="button button-primary" id="dsmkc-browser-scan"><?php esc_html_e( 'Escanear la web (completo)', 'dsmk-legal' ); ?></button>
		<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dsmkc_scan' ), 'dsmkc_scan' ) ); ?>"><?php esc_html_e( 'Solo escaneo de servidor', 'dsmk-legal' ); ?></a>
	</p>
	<div id="dsmkc-scan-progress" class="dsmkc-progress" hidden><div class="dsmkc-progress-bar"></div><p class="dsmkc-progress-text"></p></div>
	<?php if ( ! empty( $log['date'] ) ) : ?>
		<p><?php echo esc_html( sprintf( __( 'Último escaneo: %1$s (%2$s, %3$d páginas).', 'dsmk-legal' ), wp_date( 'd/m/Y H:i', $log['date'] ), $log['via'], $log['scanned'] ) ); ?></p>
		<?php if ( ! empty( $log['errors'] ) ) : ?><p class="dsmkc-fail"><?php echo esc_html( implode( ' | ', $log['errors'] ) ); ?></p><?php endif; ?>
	<?php endif; ?>
</div>

<div class="dsmkc-card">
	<h2><?php esc_html_e( 'Servicios detectados', 'dsmk-legal' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Todos los servicios conocidos que no son necesarios se bloquean siempre hasta el consentimiento, aunque no aparezcan aquí. Esta lista determina lo que se documenta en la política y en el panel.', 'dsmk-legal' ); ?></p>
	<table class="widefat striped">
		<thead><tr><th><?php esc_html_e( 'Servicio', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Proveedor', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Categoría', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Cookies', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Detectado', 'dsmk-legal' ); ?></th></tr></thead>
		<tbody>
		<?php
		$ids = array_unique( array_merge( array( 'dsmk_cookies', 'wordpress' ), array_keys( $data['detected'] ), array_keys( DSMKC_Services::active() ) ) );
		foreach ( $ids as $id ) :
			if ( ! isset( $catalogue[ $id ] ) ) {
				continue;
			}
			$service  = $catalogue[ $id ];
			$category = DSMKC_Services::category_of( $id );
			$det      = isset( $data['detected'][ $id ] ) ? $data['detected'][ $id ] : null;
			?>
			<tr>
				<td><strong><?php echo esc_html( $service['name'] ); ?></strong></td>
				<td><?php echo esc_html( $service['provider'] ); ?></td>
				<td>
					<?php if ( in_array( $id, array( 'dsmk_cookies', 'wordpress' ), true ) ) : ?>
						<?php echo esc_html( $labels['necessary']['name'] ); ?>
					<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dsmkc-inline">
						<input type="hidden" name="action" value="dsmkc_override">
						<input type="hidden" name="service" value="<?php echo esc_attr( $id ); ?>">
						<?php wp_nonce_field( 'dsmkc_override' ); ?>
						<select name="category" onchange="this.form.submit()">
							<?php foreach ( DSMKC_Services::CATEGORIES as $cat ) : ?>
								<option value="<?php echo esc_attr( $cat ); ?>" <?php selected( $category, $cat ); ?>><?php echo esc_html( $labels[ $cat ]['name'] . ( $service['category'] === $cat ? ' ' . __( '(recomendada)', 'dsmk-legal' ) : '' ) ); ?></option>
							<?php endforeach; ?>
							<option value="excluded" <?php selected( $category, 'excluded' ); ?>><?php esc_html_e( 'No se usa en esta web (ocultar)', 'dsmk-legal' ); ?></option>
						</select>
					</form>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( implode( ', ', wp_list_pluck( $service['cookies'], 0 ) ) ); ?></td>
				<td><?php echo $det ? esc_html( wp_date( 'd/m/Y', $det['last'] ?? $det['first'] ) . ' · ' . $det['via'] ) : esc_html__( 'Integración / siempre', 'dsmk-legal' ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>

<div class="dsmkc-card">
	<h2><?php esc_html_e( 'Pendientes de clasificar', 'dsmk-legal' ); ?></h2>
	<?php if ( empty( $data['unknown'] ) ) : ?>
		<p class="dsmkc-ok"><?php esc_html_e( 'No hay cookies ni recursos sin clasificar.', 'dsmk-legal' ); ?></p>
	<?php else : ?>
		<p class="description"><?php esc_html_e( 'El escáner ha encontrado elementos que no están en el catálogo. Clasifícalos para que aparezcan en la política (o ignóralos si no instalan cookies). Los dominios de terceros desconocidos puedes bloquearlos en «Avanzado».', 'dsmk-legal' ); ?></p>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Nombre', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Tipo', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Clasificar', 'dsmk-legal' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $data['unknown'] as $name => $info ) : ?>
				<tr>
					<td><code><?php echo esc_html( $name ); ?></code></td>
					<td><?php echo esc_html( array( 'cookie' => 'Cookie', 'storage' => 'localStorage', 'domain' => __( 'Dominio de terceros', 'dsmk-legal' ) )[ $info['type'] ] ?? $info['type'] ); ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dsmkc-classify">
							<input type="hidden" name="action" value="dsmkc_classify">
							<input type="hidden" name="name" value="<?php echo esc_attr( $name ); ?>">
							<?php wp_nonce_field( 'dsmkc_classify' ); ?>
							<select name="category"><?php foreach ( DSMKC_Services::CATEGORIES as $cat ) : ?><option value="<?php echo esc_attr( $cat ); ?>"><?php echo esc_html( $labels[ $cat ]['name'] ); ?></option><?php endforeach; ?></select>
							<input type="text" name="provider" placeholder="<?php esc_attr_e( 'Titular (Propia o empresa)', 'dsmk-legal' ); ?>" required>
							<input type="text" name="purpose" placeholder="<?php esc_attr_e( 'Finalidad', 'dsmk-legal' ); ?>" required>
							<input type="text" name="duration" placeholder="<?php esc_attr_e( 'Duración (ej. 1 año)', 'dsmk-legal' ); ?>" required>
							<select name="party"><option value="propia"><?php esc_html_e( 'Propia', 'dsmk-legal' ); ?></option><option value="tercero"><?php esc_html_e( 'De terceros', 'dsmk-legal' ); ?></option></select>
							<button class="button button-primary"><?php esc_html_e( 'Guardar', 'dsmk-legal' ); ?></button>
						</form>
						<a class="button-link" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dsmkc_ignore&name=' . rawurlencode( $name ) ), 'dsmkc_ignore' ) ); ?>"><?php esc_html_e( 'Ignorar', 'dsmk-legal' ); ?></a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>

<div class="dsmkc-card">
	<h2><?php esc_html_e( 'Cookies añadidas a mano', 'dsmk-legal' ); ?></h2>
	<?php if ( empty( $data['manual'] ) ) : ?>
		<p><?php esc_html_e( 'Ninguna.', 'dsmk-legal' ); ?></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Cookie', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Categoría', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Titular', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Finalidad', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Duración', 'dsmk-legal' ); ?></th><th></th></tr></thead>
			<tbody>
			<?php foreach ( $data['manual'] as $i => $row ) : ?>
				<tr><td><code><?php echo esc_html( $row['name'] ); ?></code></td><td><?php echo esc_html( $labels[ $row['category'] ]['name'] ); ?></td><td><?php echo esc_html( $row['provider'] . ' · ' . ( 'propia' === $row['party'] ? __( 'Propia', 'dsmk-legal' ) : __( 'De terceros', 'dsmk-legal' ) ) ); ?></td><td><?php echo esc_html( $row['purpose'] ); ?></td><td><?php echo esc_html( $row['duration'] ); ?></td>
				<td><a class="button-link-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dsmkc_manual_delete&i=' . (int) $i ), 'dsmkc_manual_delete' ) ); ?>"><?php esc_html_e( 'Eliminar', 'dsmk-legal' ); ?></a></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<h3><?php esc_html_e( 'Añadir cookie', 'dsmk-legal' ); ?></h3>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dsmkc-classify">
		<input type="hidden" name="action" value="dsmkc_classify">
		<?php wp_nonce_field( 'dsmkc_classify' ); ?>
		<input type="text" name="name" placeholder="<?php esc_attr_e( 'Nombre (admite *)', 'dsmk-legal' ); ?>" required>
		<select name="category"><?php foreach ( DSMKC_Services::CATEGORIES as $cat ) : ?><option value="<?php echo esc_attr( $cat ); ?>"><?php echo esc_html( $labels[ $cat ]['name'] ); ?></option><?php endforeach; ?></select>
		<input type="text" name="provider" placeholder="<?php esc_attr_e( 'Titular', 'dsmk-legal' ); ?>" required>
		<input type="text" name="purpose" placeholder="<?php esc_attr_e( 'Finalidad', 'dsmk-legal' ); ?>" required>
		<input type="text" name="duration" placeholder="<?php esc_attr_e( 'Duración', 'dsmk-legal' ); ?>" required>
		<select name="party"><option value="propia"><?php esc_html_e( 'Propia', 'dsmk-legal' ); ?></option><option value="tercero"><?php esc_html_e( 'De terceros', 'dsmk-legal' ); ?></option></select>
		<button class="button"><?php esc_html_e( 'Añadir', 'dsmk-legal' ); ?></button>
	</form>
</div>
		<?php
		$this->settings_form( 'cookies_servicios', array( 'servicios' ) );
	}

	/**
	 * Pestaña Documentos: estado de cada documento y botones para crear o
	 * asignar su página (nada se crea sin pulsar un botón, 2.0.1).
	 *
	 * @since  2.0.0
	 * @since  2.0.1 Acciones por documento, asignación de páginas existentes y aviso de duplicados.
	 * @return void
	 */
	private function tab_documentos() {
		$missing = array();
		?>
<div class="dsmkc-card">
	<h2><?php esc_html_e( 'Documentos y páginas', 'dsmk-legal' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Nada se crea solo. Para cada documento activo, usa una página que ya tenga la web (recomendado si existe) o crea una nueva. Si la web ya tiene una página parecida, aparece preseleccionada.', 'dsmk-legal' ); ?></p>
	<table class="widefat striped dsmkc-docs">
		<thead><tr><th><?php esc_html_e( 'Documento', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Estado', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Acción', 'dsmk-legal' ); ?></th></tr></thead>
		<tbody>
		<?php foreach ( DSMKC_Documents::registry() as $id => $doc ) : ?>
			<?php
			$active   = empty( $doc['toggle'] ) || DSMKC_Settings::get( $doc['toggle'] );
			$page_id  = DSMKC_Documents::assigned_page( $id );
			$suggest  = $page_id ? 0 : DSMKC_Documents::suggest_existing( $id );
			$external = ! empty( $doc['external'] );
			if ( $active && ! $page_id && ! $external && ! $suggest && ! empty( $doc['setting'] ) ) {
				$missing[] = $doc['title'];
			}
			?>
			<tr>
				<td><strong><?php echo esc_html( $doc['title'] ); ?></strong><br><code>[<?php echo esc_html( $doc['shortcode'] ); ?>]</code></td>
				<td>
					<?php if ( ! $active ) : ?>
						— <?php esc_html_e( 'Desactivado', 'dsmk-legal' ); ?>
					<?php elseif ( $page_id ) : ?>
						<a href="<?php echo esc_url( get_permalink( $page_id ) ); ?>" target="_blank"><?php echo esc_html( get_the_title( $page_id ) ); ?></a>
						<?php if ( ! DSMKC_Documents::page_has_shortcode( $page_id, $doc['shortcode'] ) ) : ?>
							<br><span class="dsmkc-warn"><?php esc_html_e( 'La página no usa el shortcode: muestra su propio texto, no el del plugin.', 'dsmk-legal' ); ?></span>
						<?php else : ?>
							<br><span class="dsmkc-ok">✔ <?php esc_html_e( 'Texto generado por el plugin', 'dsmk-legal' ); ?></span>
						<?php endif; ?>
					<?php elseif ( $external ) : ?>
						<?php esc_html_e( 'Gestionada por otro plugin', 'dsmk-legal' ); ?>
					<?php elseif ( $suggest ) : ?>
						<span class="dsmkc-warn"><?php printf( esc_html__( 'Sin asignar. La web ya tiene «%s».', 'dsmk-legal' ), esc_html( get_the_title( $suggest ) ) ); ?></span>
					<?php else : ?>
						<span class="dsmkc-warn"><?php esc_html_e( 'Sin página', 'dsmk-legal' ); ?></span>
					<?php endif; ?>
				</td>
				<td>
					<?php if ( $active && ! empty( $doc['setting'] ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dsmkc-inline">
						<input type="hidden" name="action" value="dsmkc_assign_page">
						<input type="hidden" name="doc" value="<?php echo esc_attr( $id ); ?>">
						<?php wp_nonce_field( 'dsmkc_assign_page' ); ?>
						<?php
						wp_dropdown_pages(
							array(
								'name'              => 'page_id',
								'selected'          => $page_id ? $page_id : $suggest,
								'show_option_none'  => esc_html__( '— Elegir página existente —', 'dsmk-legal' ),
								'option_none_value' => '0',
								'post_status'       => array( 'publish', 'draft', 'private' ),
							)
						);
						?>
						<label><input type="checkbox" name="replace" value="1"> <?php esc_html_e( 'Sustituir su contenido por el texto del plugin', 'dsmk-legal' ); ?></label>
						<button class="button"><?php esc_html_e( 'Usar esta página', 'dsmk-legal' ); ?></button>
					</form>
					<?php if ( ! $page_id && ! $external ) : ?>
						<a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dsmkc_create_page&doc=' . rawurlencode( $id ) ), 'dsmkc_create_page' ) ); ?>"
							<?php if ( $suggest ) : ?>onclick="return confirm('<?php echo esc_js( sprintf( __( 'La web ya tiene la página «%s». ¿Crear otra nueva de todos modos?', 'dsmk-legal' ), get_the_title( $suggest ) ) ); ?>');"<?php endif; ?>>
							<?php esc_html_e( 'Crear página nueva', 'dsmk-legal' ); ?></a>
					<?php endif; ?>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php if ( $missing ) : ?>
	<p><a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dsmkc_create_pages' ), 'dsmkc_create_pages' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Se crearán estas páginas nuevas:', 'dsmk-legal' ) . "\n- " . implode( "\n- ", $missing ) . "\n\n" . __( 'Los documentos para los que la web ya tiene una página parecida no se crean: asígnalos con «Usar esta página».', 'dsmk-legal' ) ); ?>');"><?php printf( esc_html__( 'Crear las páginas que faltan (%d)', 'dsmk-legal' ), count( $missing ) ); ?></a></p>
	<?php endif; ?>
	<p class="description"><?php esc_html_e( '«Sustituir su contenido» guarda una copia del texto anterior (revisiones de WordPress) y, en páginas de Elementor, desactiva el constructor solo en esa página. Sin marcarlo, la página solo se enlaza y conserva su texto.', 'dsmk-legal' ); ?></p>
</div>
<div class="dsmkc-card">
	<h3><?php esc_html_e( 'Otros shortcodes', 'dsmk-legal' ); ?></h3>
	<table class="widefat striped">
		<tbody>
			<tr><td><code>[dsmk_enlaces_legales]</code></td><td><?php esc_html_e( 'Enlaces a todos los documentos activos + «Configurar cookies», para el pie (Elementor: widget Shortcode).', 'dsmk-legal' ); ?></td></tr>
			<tr><td><code>[dsmk_cookies_tabla]</code></td><td><?php esc_html_e( 'Solo las tablas de cookies.', 'dsmk-legal' ); ?></td></tr>
			<tr><td><code>[dsmk_cookies_ajustes texto="Configurar cookies"]</code></td><td><?php esc_html_e( 'Enlace que abre el panel de cookies.', 'dsmk-legal' ); ?></td></tr>
			<tr><td><code>[dsmk_desistimiento_formulario]</code></td><td><?php esc_html_e( 'Función de desistimiento online: la aporta el plugin de desistimiento de DSMK (filtro dsmkc_withdrawal_form); sin él, botón por email.', 'dsmk-legal' ); ?></td></tr>
			<tr><td><code>#dsmk-cookies</code></td><td><?php esc_html_e( 'Enlace para cualquier botón o menú (también en Elementor) que abre el panel de cookies. También sirve la clase CSS «dsmkc-open-settings».', 'dsmk-legal' ); ?></td></tr>
		</tbody>
	</table>
</div>
		<?php
		$this->settings_form( 'documentos', array( 'documentos' ) );
	}

	/**
	 * Pestaña Registro.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function tab_registro() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Navegación y búsqueda de solo lectura.
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable
		$result = DSMKC_Consent_Log::query( $paged, 50, $search );
		$pages  = max( 1, (int) ceil( $result['total'] / 50 ) );
		$this->settings_form( 'registro', array( 'registro' ) );
		?>
<div class="dsmkc-card">
	<h2><?php esc_html_e( 'Consentimientos registrados', 'dsmk-legal' ); ?></h2>
	<form method="get" class="dsmkc-inline">
		<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
		<input type="hidden" name="tab" value="registro">
		<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'ID de consentimiento', 'dsmk-legal' ); ?>">
		<button class="button"><?php esc_html_e( 'Buscar', 'dsmk-legal' ); ?></button>
		<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dsmkc_export_csv&s=' . rawurlencode( $search ) ), 'dsmkc_export_csv' ) ); ?>"><?php esc_html_e( 'Exportar CSV', 'dsmk-legal' ); ?></a>
	</form>
	<p class="description"><?php esc_html_e( 'El visitante puede ver su ID de consentimiento con DSMKCookies.consent().id en la consola, o te lo puede facilitar para localizar su registro.', 'dsmk-legal' ); ?></p>
	<table class="widefat striped">
		<thead><tr><th><?php esc_html_e( 'Fecha (UTC)', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'ID', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Acción', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Categorías', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Servicios', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Política / banner', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'IP anonimizada', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'URL', 'dsmk-legal' ); ?></th></tr></thead>
		<tbody>
		<?php if ( ! $result['rows'] ) : ?>
			<tr><td colspan="8"><?php esc_html_e( 'Sin registros todavía.', 'dsmk-legal' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $result['rows'] as $row ) : ?>
			<tr><td><?php echo esc_html( $row['created_at'] ); ?></td><td><code><?php echo esc_html( $row['consent_id'] ); ?></code></td><td><?php echo esc_html( $row['action'] ); ?></td><td><?php echo esc_html( $row['categories'] ); ?></td><td><?php echo esc_html( $row['services'] ); ?></td><td><?php echo esc_html( $row['policy_version'] . ' / ' . $row['banner_hash'] ); ?></td><td><?php echo esc_html( $row['ip_anon'] ); ?></td><td><?php echo esc_html( $row['url'] ); ?></td></tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php if ( $pages > 1 ) : ?>
		<p class="dsmkc-pager">
		<?php
		echo wp_kses_post(
			paginate_links(
				array(
					'base'    => add_query_arg( 'paged', '%#%', self::url( 'registro', array( 's' => $search ) ) ),
					'format'  => '',
					'current' => $paged,
					'total'   => $pages,
				)
			)
		);
		?>
		</p>
	<?php endif; ?>
	<h3><?php esc_html_e( 'Versiones del banner mostradas', 'dsmk-legal' ); ?></h3>
	<table class="widefat striped">
		<thead><tr><th><?php esc_html_e( 'Huella', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Desde', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Política', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Textos', 'dsmk-legal' ); ?></th></tr></thead>
		<tbody>
		<?php foreach ( array_reverse( (array) DSMKC_Settings::data()['banner_history'], true ) as $hash => $item ) : ?>
			<tr><td><code><?php echo esc_html( $hash ); ?></code></td><td><?php echo esc_html( wp_date( 'd/m/Y H:i', $item['date'] ) ); ?></td><td><?php echo (int) $item['version']; ?></td><td><details><summary><?php echo esc_html( $item['texts']['title'] ); ?></summary><p><?php echo esc_html( $item['texts']['text'] ); ?></p><p>[<?php echo esc_html( $item['texts']['accept'] ); ?>] [<?php echo esc_html( $item['texts']['reject'] ); ?>] [<?php echo esc_html( $item['texts']['settings'] ); ?>]</p></details></td></tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>
		<?php
	}

	/**
	 * Pestaña Auditoría: escaneo completo con un botón, resultado de la última
	 * auditoría, histórico y acceso al informe técnico de cada una.
	 *
	 * @since  1.0.0
	 * @since  2.1.0 Auditoría automatizada e histórico.
	 * @return void
	 */
	private function tab_informe() {
		$latest  = DSMKC_Audit::get();
		$history = DSMKC_Audit::history();
		$groups  = DSMKC_Report::groups();
		$audit   = wp_nonce_url( admin_url( 'admin-post.php?action=dsmkc_audit' ), 'dsmkc_audit' );
		?>
<div class="dsmkc-card">
	<h2><?php esc_html_e( 'Auditoría técnica', 'dsmk-legal' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Un clic: escanea la web desde tu navegador (cookies creadas por JavaScript) y desde el servidor (cada página como visitante sin consentimiento y como escáner), revisa la configuración, el SSL, las cabeceras, los formularios y los enlaces legales, y guarda el resultado en el histórico. Tarda entre 20 y 60 segundos.', 'dsmk-legal' ); ?></p>
	<p>
		<button type="button" class="button button-primary button-hero" id="dsmkc-audit" data-href="<?php echo esc_url( $audit ); ?>"><?php esc_html_e( 'Ejecutar auditoría completa', 'dsmk-legal' ); ?></button>
		<a class="button" href="<?php echo esc_url( $audit ); ?>"><?php esc_html_e( 'Solo desde el servidor (más rápido)', 'dsmk-legal' ); ?></a>
	</p>
	<div id="dsmkc-scan-progress" class="dsmkc-progress" hidden><div class="dsmkc-progress-bar"></div><p class="dsmkc-progress-text"></p></div>
	<p class="description"><?php echo esc_html( DSMKC_Settings::get( 'escaneo_semanal' ) ? __( 'Además se ejecuta automáticamente una vez por semana (desactivable en «Cookies: servicios»).', 'dsmk-legal' ) : __( 'La auditoría semanal automática está desactivada.', 'dsmk-legal' ) ); ?></p>
</div>

<?php if ( ! $latest ) : ?>
	<div class="dsmkc-card"><p><?php esc_html_e( 'Todavía no hay auditorías. Ejecuta la primera con el botón de arriba.', 'dsmk-legal' ); ?></p></div>
<?php else : ?>
<div class="dsmkc-card">
	<h2><?php printf( esc_html__( 'Última auditoría: %s', 'dsmk-legal' ), esc_html( wp_date( 'd/m/Y H:i', $latest['date'] ) ) ); ?></h2>
	<p class="dsmkc-big"><span><?php echo (int) $latest['score']; ?>/100</span> <span class="dsmkc-ok"><?php echo (int) $latest['summary']['ok']; ?> ✔</span> <span class="dsmkc-warn"><?php echo (int) $latest['summary']['warn']; ?> !</span> <span class="dsmkc-fail"><?php echo (int) $latest['summary']['fail']; ?> ✘</span></p>
	<p>
		<a class="button button-primary" target="_blank" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dsmkc_report&audit=' . rawurlencode( $latest['id'] ) ), 'dsmkc_report' ) ); ?>"><?php esc_html_e( 'Abrir informe técnico (PDF)', 'dsmk-legal' ); ?></a>
	</p>
	<?php foreach ( $groups as $gkey => $gname ) : ?>
		<?php
		$items = array_filter(
			$latest['checks'],
			static function ( $c ) use ( $gkey ) {
				return ( isset( $c['group'] ) ? $c['group'] : 'otros' ) === $gkey;
			}
		);
		if ( ! $items ) {
			continue;
		}
		?>
		<h3><?php echo esc_html( $gname ); ?></h3>
		<ul class="dsmkc-checklist">
			<?php foreach ( $items as $check ) : ?>
				<li class="dsmkc-<?php echo esc_attr( $check['status'] ); ?>"><strong><?php echo esc_html( $check['label'] ); ?></strong><br><span><?php echo esc_html( wp_strip_all_tags( $check['detail'] ) ); ?></span></li>
			<?php endforeach; ?>
		</ul>
	<?php endforeach; ?>
</div>

<div class="dsmkc-card">
	<h2><?php esc_html_e( 'Histórico de auditorías', 'dsmk-legal' ); ?></h2>
	<table class="widefat striped">
		<thead><tr><th><?php esc_html_e( 'Fecha', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Origen', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Puntuación', 'dsmk-legal' ); ?></th><th>✔ / ! / ✘</th><th><?php esc_html_e( 'Páginas', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Rastreadores sin consentimiento', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Servicios nuevos', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Duración', 'dsmk-legal' ); ?></th><th></th></tr></thead>
		<tbody>
		<?php foreach ( $history as $h ) : ?>
			<tr>
				<td><?php echo esc_html( wp_date( 'd/m/Y H:i', $h['date'] ) ); ?></td>
				<td><?php echo esc_html( 'automatico' === $h['trigger'] ? __( 'Automática', 'dsmk-legal' ) : __( 'Manual', 'dsmk-legal' ) . ( $h['user'] ? ' (' . $h['user'] . ')' : '' ) ); ?></td>
				<td><strong><?php echo (int) $h['score']; ?></strong></td>
				<td><?php echo esc_html( $h['summary']['ok'] . ' / ' . $h['summary']['warn'] . ' / ' . $h['summary']['fail'] ); ?></td>
				<td><?php echo (int) $h['totals']['pages']; ?></td>
				<td class="<?php echo $h['totals']['leaks'] ? 'dsmkc-fail' : 'dsmkc-ok'; ?>"><?php echo (int) $h['totals']['leaks']; ?></td>
				<td><?php echo esc_html( $h['new_services'] ? implode( ', ', $h['new_services'] ) : '—' ); ?></td>
				<td><?php echo esc_html( $h['duration'] . ' s' ); ?></td>
				<td><a target="_blank" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dsmkc_report&audit=' . rawurlencode( $h['id'] ) ), 'dsmkc_report' ) ); ?>"><?php esc_html_e( 'Informe', 'dsmk-legal' ); ?></a></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<p class="description"><?php esc_html_e( 'Se guardan las 50 últimas auditorías; el detalle por página y el entorno técnico, en las 10 más recientes.', 'dsmk-legal' ); ?></p>
</div>

<div class="dsmkc-card">
	<h2><?php esc_html_e( 'Declaración técnica de cumplimiento', 'dsmk-legal' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Texto para justificar el cumplimiento ante un cliente, un auditor o cualquier organismo. Adjunta el informe técnico en PDF.', 'dsmk-legal' ); ?></p>
	<textarea class="large-text" rows="9" readonly id="dsmkc-declaracion"><?php echo esc_textarea( DSMKC_Report::declaration_text( $latest ) ); ?></textarea>
	<p><button type="button" class="button" data-dsmkc-copy="#dsmkc-declaracion"><?php esc_html_e( 'Copiar texto', 'dsmk-legal' ); ?></button></p>
</div>
<?php endif; ?>
		<?php
	}

	/**
	 * Pestaña Herramientas: autodetección, IA, importar/exportar, reconsentimiento.
	 *
	 * @since  2.0.0
	 * @return void
	 */
	private function tab_herramientas() {
		$fields   = DSMKC_Settings::fields();
		$detected = DSMKC_Tools::detect();
		$update   = DSMKC_Updater::status();
		?>
<div class="dsmkc-grid">
<div class="dsmkc-card">
	<h2><?php esc_html_e( 'Actualizaciones de DSMK Legal', 'dsmk-legal' ); ?></h2>
	<table class="widefat striped">
		<tbody>
			<tr><td><?php esc_html_e( 'Versión instalada', 'dsmk-legal' ); ?></td><td><strong><?php echo esc_html( $update['installed'] ); ?></strong></td></tr>
			<tr><td><?php esc_html_e( 'Última versión publicada', 'dsmk-legal' ); ?></td><td>
				<?php
				if ( '' !== $update['available'] ) {
					echo '<strong>' . esc_html( $update['available'] ) . '</strong>';
					if ( $update['newer'] ) {
						echo ' — <a href="' . esc_url( self_admin_url( 'update-core.php' ) ) . '">' . esc_html__( 'Actualizar ahora', 'dsmk-legal' ) . '</a>';
					}
				} else {
					esc_html_e( 'Sin comprobar todavía', 'dsmk-legal' );
				}
				?>
			</td></tr>
			<tr><td><?php esc_html_e( 'Última comprobación', 'dsmk-legal' ); ?></td><td><?php echo $update['checked'] ? esc_html( wp_date( get_option( 'date_format' ) . ' H:i', $update['checked'] ) ) : '—'; ?></td></tr>
			<tr><td><?php esc_html_e( 'Servidor', 'dsmk-legal' ); ?></td><td><code><?php echo esc_html( '' !== $update['url'] ? $update['url'] : __( 'URL no válida (debe ser https)', 'dsmk-legal' ) ); ?></code></td></tr>
			<?php if ( '' !== $update['error'] ) : ?>
			<tr><td><?php esc_html_e( 'Error', 'dsmk-legal' ); ?></td><td><span class="dsmkc-fail"><?php echo esc_html( $update['error'] ); ?></span></td></tr>
			<?php endif; ?>
		</tbody>
	</table>
	<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dsmkc_check_update' ), 'dsmkc_check_update' ) ); ?>"><?php esc_html_e( 'Comprobar ahora', 'dsmk-legal' ); ?></a></p>
	<p class="description"><?php esc_html_e( 'WordPress comprueba solo cada 12 horas. Solo se instalan versiones firmadas por DSMK, y cada ZIP se verifica con su huella SHA-256 antes de instalarse.', 'dsmk-legal' ); ?></p>
	<?php if ( ! DSMKC_Updater::settings_allowed() ) : ?>
	<p class="description"><?php esc_html_e( 'Multisite: el origen de las actualizaciones lo fija la red (constantes DSMK_LEGAL_UPDATE_URL y DSMK_LEGAL_UPDATE_KEY en wp-config.php) y las automáticas se activan en Red › Plugins. Activa DSMK Legal para toda la red.', 'dsmk-legal' ); ?></p>
	<?php endif; ?>
</div>
<div class="dsmkc-card">
	<h2><?php esc_html_e( '1. Detectar datos automáticamente', 'dsmk-legal' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Busca datos para los campos vacíos en WooCommerce, en los ajustes de WordPress y en los textos legales que ya tenga la web. Marca los que quieras aplicar.', 'dsmk-legal' ); ?></p>
	<?php if ( ! $detected ) : ?>
		<p><?php esc_html_e( 'No hay sugerencias: los campos detectables ya están rellenos o no se han encontrado datos.', 'dsmk-legal' ); ?></p>
	<?php else : ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="dsmkc_detect_apply">
		<?php wp_nonce_field( 'dsmkc_detect_apply' ); ?>
		<table class="widefat striped">
			<tbody>
			<?php foreach ( $detected as $key => $item ) : ?>
				<tr>
					<td><input type="checkbox" name="keys[]" value="<?php echo esc_attr( $key ); ?>" checked></td>
					<td><strong><?php echo esc_html( $fields[ $key ]['label'] ); ?></strong><br><small><?php echo esc_html( $item['source'] ); ?></small></td>
					<td><?php echo esc_html( is_array( $item['value'] ) ? implode( ', ', $item['value'] ) : (string) $item['value'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p><button class="button button-primary"><?php esc_html_e( 'Aplicar seleccionados', 'dsmk-legal' ); ?></button></p>
	</form>
	<?php endif; ?>
</div>

<div class="dsmkc-card">
	<h2><?php esc_html_e( '2. Rellenar con IA', 'dsmk-legal' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Descarga el briefing (describe todos los campos, sus valores permitidos y lo que ya está relleno) y pégalo en Claude junto con la información del cliente. Te devolverá un JSON: pégalo en el punto 3.', 'dsmk-legal' ); ?></p>
	<p>
		<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dsmkc_ai_brief' ), 'dsmkc_ai_brief' ) ); ?>"><?php esc_html_e( 'Descargar briefing para IA (.md)', 'dsmk-legal' ); ?></a>
		<button type="button" class="button" data-dsmkc-copy="#dsmkc-ai-brief"><?php esc_html_e( 'Copiar al portapapeles', 'dsmk-legal' ); ?></button>
	</p>
	<textarea id="dsmkc-ai-brief" class="large-text code" rows="6" readonly><?php echo esc_textarea( DSMKC_Tools::ai_brief() ); ?></textarea>
</div>

<div class="dsmkc-card">
	<h2><?php esc_html_e( '3. Importar', 'dsmk-legal' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Pega el JSON (de la IA, de otra web o de DSMK Cookies 1.x) o sube el archivo. Solo se aplican campos conocidos y todo se valida. Los IDs de página nunca se importan.', 'dsmk-legal' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
		<input type="hidden" name="action" value="dsmkc_import_settings">
		<?php wp_nonce_field( 'dsmkc_import_settings' ); ?>
		<textarea name="json" class="large-text code" rows="7" placeholder='{"plugin":"dsmk-legal","settings":{ … }}'></textarea>
		<p><input type="file" name="file" accept="application/json,.json"></p>
		<p><label><input type="checkbox" name="only_empty" value="1"> <?php esc_html_e( 'Rellenar solo los campos vacíos (no sobrescribir lo que ya hay)', 'dsmk-legal' ); ?></label></p>
		<p><button class="button button-primary"><?php esc_html_e( 'Importar', 'dsmk-legal' ); ?></button></p>
	</form>
</div>

<div class="dsmkc-card">
	<h2><?php esc_html_e( '4. Exportar', 'dsmk-legal' ); ?></h2>
	<p class="description"><?php esc_html_e( 'JSON con toda la configuración (sin IDs de página) para replicarla en otra web o guardarla como plantilla.', 'dsmk-legal' ); ?></p>
	<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dsmkc_export_settings' ), 'dsmkc_export_settings' ) ); ?>"><?php esc_html_e( 'Exportar ajustes (JSON)', 'dsmk-legal' ); ?></a></p>
	<h2><?php esc_html_e( 'Volver a pedir el consentimiento de cookies', 'dsmk-legal' ); ?></h2>
	<p><?php esc_html_e( 'Todos los visitantes volverán a ver el banner. Úsalo si cambias las finalidades de las cookies.', 'dsmk-legal' ); ?></p>
	<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dsmkc_bump' ), 'dsmkc_bump' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( '¿Volver a mostrar el banner a todos los visitantes?', 'dsmk-legal' ) ); ?>');"><?php esc_html_e( 'Pedir de nuevo el consentimiento', 'dsmk-legal' ); ?></a></p>
</div>
</div>
		<?php
	}

	/* =====================================================================
	 * Acciones
	 * ===================================================================== */

	/**
	 * Comprueba capacidad y nonce de una acción admin-post.
	 *
	 * @since  1.0.0
	 * @param  string $action Acción.
	 * @return void Termina la ejecución si no está autorizada.
	 */
	private function guard( $action ) {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'No tienes permisos suficientes.', 'dsmk-legal' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirige a una pestaña con mensaje.
	 *
	 * El código del mensaje va en la URL; el detalle, en un transient del
	 * usuario que se lee y borra al mostrar el aviso (2.2.0).
	 *
	 * @since  1.0.0
	 * @param  string $tab  Pestaña.
	 * @param  string $msg  Mensaje.
	 * @param  string $info Información extra.
	 * @return void
	 */
	private function back( $tab, $msg, $info = '' ) {
		$args = array( 'dsmkc_msg' => $msg );
		if ( '' !== $info ) {
			set_transient( 'dsmkc_notice_' . get_current_user_id(), sanitize_text_field( $info ), 5 * MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( self::url( $tab, $args ) );
		exit;
	}

	/**
	 * Guardar ajustes de una pestaña.
	 *
	 * Handler de admin-post (`admin_post_dsmkc_save`). Comprueba capacidad y nonce con guard().
	 *
	 * @since  1.0.0
	 * @return void Redirige o envía una descarga y termina la ejecución.
	 */
	public function handle_save() {
		$this->guard( 'dsmkc_save' );
		$tabs = self::tabs();
		$tab  = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';
		if ( ! isset( $tabs[ $tab ] ) ) {
			$this->back( 'inicio', 'error' );
		}
		$schema = DSMKC_Settings::schema();
		$fields = array();
		foreach ( $tabs[ $tab ][1] as $section ) {
			$fields = array_merge( $fields, array_keys( $schema[ $section ]['fields'] ) );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Saneado campo a campo en DSMKC_Settings::save().
		$input = isset( $_POST['dsmkc'] ) && is_array( $_POST['dsmkc'] ) ? wp_unslash( $_POST['dsmkc'] ) : array();
		DSMKC_Settings::save( $input, $fields ); // Dispara la creación automática de páginas (DSMKC_Documents).
		$this->back( $tab, 'saved', self::created_pages_info() );
	}

	/**
	 * Crear todas las páginas que faltan (salvo las que tienen una página parecida).
	 *
	 * Handler de admin-post (`admin_post_dsmkc_create_pages`). Comprueba capacidad y nonce con guard().
	 *
	 * @since  2.0.0
	 * @return void Redirige o envía una descarga y termina la ejecución.
	 */
	public function handle_create_pages() {
		$this->guard( 'dsmkc_create_pages' );
		DSMKC_Documents::create_pages( null, true );
		DSMKC_Plugin::purge_caches();
		$info = self::created_pages_info();
		if ( DSMKC_Documents::$last_skipped ) {
			$titles = array();
			foreach ( array_keys( DSMKC_Documents::$last_skipped ) as $id ) {
				$titles[] = DSMKC_Documents::get( $id )['title'];
			}
			/* translators: %s: documentos. */
			$info .= ' ' . sprintf( __( 'No creadas porque ya existe una página parecida (asígnala): %s.', 'dsmk-legal' ), implode( ', ', $titles ) );
		}
		$this->back( 'documentos', 'pages', trim( $info ) );
	}

	/**
	 * Crear la página de un documento.
	 *
	 * Handler de admin-post (`admin_post_dsmkc_create_page`). Comprueba capacidad y nonce con guard().
	 *
	 * @since  2.0.1
	 * @return void Redirige o envía una descarga y termina la ejecución.
	 */
	public function handle_create_page() {
		$this->guard( 'dsmkc_create_page' );
		$doc = isset( $_GET['doc'] ) ? sanitize_key( wp_unslash( $_GET['doc'] ) ) : '';
		if ( ! DSMKC_Documents::get( $doc ) ) {
			$this->back( 'documentos', 'error' );
		}
		DSMKC_Documents::create_pages( $doc, false );
		DSMKC_Plugin::purge_caches();
		$this->back( 'documentos', 'pages', self::created_pages_info() );
	}

	/**
	 * Asignar una página existente a un documento.
	 *
	 * Handler de admin-post (`admin_post_dsmkc_assign_page`). Comprueba capacidad y nonce con guard().
	 *
	 * @since  2.0.1
	 * @return void Redirige o envía una descarga y termina la ejecución.
	 */
	public function handle_assign_page() {
		$this->guard( 'dsmkc_assign_page' );
		$doc     = isset( $_POST['doc'] ) ? sanitize_key( wp_unslash( $_POST['doc'] ) ) : '';
		$page_id = isset( $_POST['page_id'] ) ? absint( $_POST['page_id'] ) : 0;
		if ( ! $page_id || ! DSMKC_Documents::assign_page( $doc, $page_id, ! empty( $_POST['replace'] ) ) ) {
			$this->back( 'documentos', 'error', __( 'Elige una página.', 'dsmk-legal' ) );
		}
		DSMKC_Plugin::purge_caches();
		$this->back( 'documentos', 'assigned' );
	}

	/**
	 * Texto con las páginas creadas en esta petición.
	 *
	 * @since  2.0.0
	 * @return string
	 */
	private static function created_pages_info() {
		$created = DSMKC_Documents::$last_created;
		if ( ! $created ) {
			return '';
		}
		$titles = array();
		foreach ( array_keys( $created ) as $id ) {
			$doc      = DSMKC_Documents::get( $id );
			$titles[] = $doc ? $doc['title'] : $id;
		}
		/* translators: %s: lista de páginas. */
		return sprintf( __( 'Páginas creadas: %s.', 'dsmk-legal' ), implode( ', ', $titles ) );
	}

	/**
	 * Escaneo de servidor.
	 *
	 * Handler de admin-post (`admin_post_dsmkc_scan`). Comprueba capacidad y nonce con guard().
	 *
	 * @since  1.0.0
	 * @return void Redirige o envía una descarga y termina la ejecución.
	 */
	public function handle_scan() {
		$this->guard( 'dsmkc_scan' );
		$result = DSMKC_Scanner::server_scan( false );
		/* translators: 1: páginas, 2: servicios nuevos, 3: pendientes. */
		$this->back( 'cookies_servicios', 'scanned', sprintf( __( '%1$d páginas · %2$d servicios nuevos · %3$d pendientes de clasificar.', 'dsmk-legal' ), $result['scanned'], count( $result['new_services'] ), count( $result['new_unknown'] ) ) );
	}

	/**
	 * Volver a pedir consentimiento.
	 *
	 * Handler de admin-post (`admin_post_dsmkc_bump`). Comprueba capacidad y nonce con guard().
	 *
	 * @since  1.0.0
	 * @return void Redirige o envía una descarga y termina la ejecución.
	 */
	public function handle_bump() {
		$this->guard( 'dsmkc_bump' );
		DSMKC_Settings::bump_version();
		DSMKC_Legal::record_banner_version();
		DSMKC_Plugin::purge_caches();
		$this->back( 'herramientas', 'bumped' );
	}

	/**
	 * Comprueba ahora si hay una versión nueva de DSMK Legal.
	 *
	 * Handler de admin-post (`admin_post_dsmkc_check_update`). Comprueba capacidad y nonce con guard().
	 *
	 * @since  2.3.0
	 * @return void Redirige a Herramientas.
	 */
	public function handle_check_update() {
		$this->guard( 'dsmkc_check_update' );
		$remote = DSMKC_Updater::check_now();
		if ( is_wp_error( $remote ) ) {
			$this->back( 'herramientas', 'error', $remote->get_error_message() );
		}
		$info = version_compare( $remote['version'], DSMKC_VERSION, '>' )
			/* translators: %s: versión. */
			? sprintf( __( 'Hay una versión nueva: %s.', 'dsmk-legal' ), $remote['version'] )
			: __( 'Ya tienes la última versión.', 'dsmk-legal' );
		$this->back( 'herramientas', 'updates', $info );
	}

	/**
	 * Exportar registros CSV.
	 *
	 * Handler de admin-post (`admin_post_dsmkc_export_csv`). Comprueba capacidad y nonce con guard().
	 *
	 * @since  1.0.0
	 * @return void Redirige o envía una descarga y termina la ejecución.
	 */
	public function handle_export_csv() {
		$this->guard( 'dsmkc_export_csv' );
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		DSMKC_Consent_Log::export_csv( $search );
	}

	/**
	 * Informe técnico imprimible de una auditoría (la indicada o la última; si no hay, ejecuta una).
	 *
	 * Handler de admin-post (`admin_post_dsmkc_report`). Comprueba capacidad y nonce con guard().
	 *
	 * @since  1.0.0
	 * @return void Redirige o envía una descarga y termina la ejecución.
	 */
	public function handle_report() {
		$this->guard( 'dsmkc_report' );
		$id     = isset( $_GET['audit'] ) ? sanitize_text_field( wp_unslash( $_GET['audit'] ) ) : '';
		$record = DSMKC_Audit::get( $id );
		if ( ! $record ) {
			$record = DSMKC_Audit::run( 'manual' );
		}
		DSMKC_Report::render_printable( $record );
	}

	/**
	 * Ejecutar una auditoría completa.
	 *
	 * Handler de admin-post (`admin_post_dsmkc_audit`). Comprueba capacidad y nonce con guard().
	 *
	 * @since  2.1.0
	 * @return void Redirige o envía una descarga y termina la ejecución.
	 */
	public function handle_audit() {
		$this->guard( 'dsmkc_audit' );
		$record = DSMKC_Audit::run( 'manual' );
		/* translators: 1: puntuación, 2: páginas, 3: segundos. */
		$this->back( 'informe', 'audited', sprintf( __( 'Puntuación %1$d/100 · %2$d páginas · %3$s s.', 'dsmk-legal' ), $record['score'], $record['totals']['pages'], $record['duration'] ) );
	}

	/**
	 * Cambiar la categoría de un servicio o excluirlo.
	 *
	 * Handler de admin-post (`admin_post_dsmkc_override`). Comprueba capacidad y nonce con guard().
	 *
	 * @since  1.0.0
	 * @return void Redirige o envía una descarga y termina la ejecución.
	 */
	public function handle_override() {
		$this->guard( 'dsmkc_override' );
		$service  = isset( $_POST['service'] ) ? sanitize_key( wp_unslash( $_POST['service'] ) ) : '';
		$category = isset( $_POST['category'] ) ? sanitize_key( wp_unslash( $_POST['category'] ) ) : '';
		$catalog  = DSMKC_Services::catalogue();
		if ( ! isset( $catalog[ $service ] ) || ( ! in_array( $category, DSMKC_Services::CATEGORIES, true ) && 'excluded' !== $category ) ) {
			$this->back( 'cookies_servicios', 'error' );
		}
		$overrides = (array) DSMKC_Settings::data()['overrides'];
		if ( $catalog[ $service ]['category'] === $category ) {
			unset( $overrides[ $service ] );
		} else {
			$overrides[ $service ] = $category;
		}
		DSMKC_Settings::update_data( array( 'overrides' => $overrides ) );
		DSMKC_Plugin::on_settings_saved();
		$this->back( 'cookies_servicios', 'override' );
	}

	/**
	 * Clasificar una cookie desconocida o añadir una manual.
	 *
	 * Handler de admin-post (`admin_post_dsmkc_classify`). Comprueba capacidad y nonce con guard().
	 *
	 * @since  1.0.0
	 * @return void Redirige o envía una descarga y termina la ejecución.
	 */
	public function handle_classify() {
		$this->guard( 'dsmkc_classify' );
		$name     = isset( $_POST['name'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['name'] ) ), 0, 120 ) : '';
		$category = isset( $_POST['category'] ) ? sanitize_key( wp_unslash( $_POST['category'] ) ) : '';
		if ( '' === $name || ! in_array( $category, DSMKC_Services::CATEGORIES, true ) ) {
			$this->back( 'cookies_servicios', 'error' );
		}
		$data             = DSMKC_Settings::data();
		$manual           = (array) $data['manual'];
		$manual[]         = array(
			'name'     => $name,
			'category' => $category,
			'provider' => isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : '',
			'purpose'  => isset( $_POST['purpose'] ) ? sanitize_text_field( wp_unslash( $_POST['purpose'] ) ) : '',
			'duration' => isset( $_POST['duration'] ) ? sanitize_text_field( wp_unslash( $_POST['duration'] ) ) : '',
			'party'    => isset( $_POST['party'] ) && 'tercero' === sanitize_key( wp_unslash( $_POST['party'] ) ) ? 'tercero' : 'propia',
		);
		$unknown = (array) $data['unknown'];
		unset( $unknown[ $name ] );
		DSMKC_Settings::update_data( array( 'manual' => $manual, 'unknown' => $unknown, 'updated' => time() ) );
		DSMKC_Plugin::on_settings_saved();
		$this->back( 'cookies_servicios', 'classified' );
	}

	/**
	 * Ignorar un elemento desconocido.
	 *
	 * Handler de admin-post (`admin_post_dsmkc_ignore`). Comprueba capacidad y nonce con guard().
	 *
	 * @since  1.0.0
	 * @return void Redirige o envía una descarga y termina la ejecución.
	 */
	public function handle_ignore() {
		$this->guard( 'dsmkc_ignore' );
		$name    = isset( $_GET['name'] ) ? sanitize_text_field( wp_unslash( $_GET['name'] ) ) : '';
		$data    = DSMKC_Settings::data();
		$unknown = (array) $data['unknown'];
		$ignored = (array) $data['ignored'];
		unset( $unknown[ $name ] );
		$ignored[] = $name;
		DSMKC_Settings::update_data( array( 'unknown' => $unknown, 'ignored' => array_values( array_unique( $ignored ) ) ) );
		$this->back( 'cookies_servicios', 'ignored' );
	}

	/**
	 * Eliminar una cookie manual.
	 *
	 * Handler de admin-post (`admin_post_dsmkc_manual_delete`). Comprueba capacidad y nonce con guard().
	 *
	 * @since  1.0.0
	 * @return void Redirige o envía una descarga y termina la ejecución.
	 */
	public function handle_manual_delete() {
		$this->guard( 'dsmkc_manual_delete' );
		$i      = isset( $_GET['i'] ) ? absint( $_GET['i'] ) : -1;
		$manual = (array) DSMKC_Settings::data()['manual'];
		if ( isset( $manual[ $i ] ) ) {
			unset( $manual[ $i ] );
			DSMKC_Settings::update_data( array( 'manual' => array_values( $manual ), 'updated' => time() ) );
			DSMKC_Plugin::on_settings_saved();
		}
		$this->back( 'cookies_servicios', 'deleted' );
	}

	/**
	 * Exportar ajustes.
	 *
	 * Handler de admin-post (`admin_post_dsmkc_export_settings`). Comprueba capacidad y nonce con guard().
	 *
	 * @since  1.0.0
	 * @return void Redirige o envía una descarga y termina la ejecución.
	 */
	public function handle_export_settings() {
		$this->guard( 'dsmkc_export_settings' );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=dsmk-legal-' . sanitize_file_name( DSMKC_Legal::domain() ) . '.json' );
		echo wp_json_encode( DSMKC_Tools::export_payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Importar ajustes (archivo o JSON pegado).
	 *
	 * Handler de admin-post (`admin_post_dsmkc_import_settings`). Comprueba capacidad y nonce con guard().
	 *
	 * @since  1.0.0
	 * @return void Redirige o envía una descarga y termina la ejecución.
	 */
	public function handle_import_settings() {
		$this->guard( 'dsmkc_import_settings' );
		$raw = isset( $_POST['json'] ) ? (string) wp_unslash( $_POST['json'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON; se valida y sanea campo a campo.
		if ( '' === trim( $raw ) && ! empty( $_FILES['file']['tmp_name'] ) ) {
			$file = $_FILES['file']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Ruta temporal de PHP validada con is_uploaded_file().
			if ( is_uploaded_file( $file ) && filesize( $file ) <= 512000 ) {
				$raw = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}
		}
		$json = DSMKC_Tools::parse_json( substr( $raw, 0, 512000 ) );
		if ( ! $json ) {
			$this->back( 'herramientas', 'error', __( 'No se encontró un JSON válido.', 'dsmk-legal' ) );
		}
		$result = DSMKC_Tools::import_payload( $json, ! empty( $_POST['only_empty'] ) );
		DSMKC_Plugin::on_settings_saved();
		/* translators: 1: campos aplicados, 2: ignorados. */
		$info = sprintf( __( '%1$d campos aplicados, %2$d ignorados.', 'dsmk-legal' ), count( $result['applied'] ), count( $result['ignored'] ) );
		if ( $result['ignored'] ) {
			$info .= ' (' . implode( ', ', array_slice( $result['ignored'], 0, 10 ) ) . ')';
		}
		$created = self::created_pages_info();
		$this->back( 'herramientas', 'imported', trim( $info . ' ' . $created ) );
	}

	/**
	 * Descargar el briefing para IA.
	 *
	 * Handler de admin-post (`admin_post_dsmkc_ai_brief`). Comprueba capacidad y nonce con guard().
	 *
	 * @since  2.0.0
	 * @return void Redirige o envía una descarga y termina la ejecución.
	 */
	public function handle_ai_brief() {
		$this->guard( 'dsmkc_ai_brief' );
		nocache_headers();
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=briefing-ia-dsmk-legal-' . sanitize_file_name( DSMKC_Legal::domain() ) . '.md' );
		echo DSMKC_Tools::ai_brief(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Descarga de texto plano Markdown.
		exit;
	}

	/**
	 * Aplicar datos autodetectados.
	 *
	 * Handler de admin-post (`admin_post_dsmkc_detect_apply`). Comprueba capacidad y nonce con guard().
	 *
	 * @since  2.0.0
	 * @return void Redirige o envía una descarga y termina la ejecución.
	 */
	public function handle_detect_apply() {
		$this->guard( 'dsmkc_detect_apply' );
		$keys = isset( $_POST['keys'] ) && is_array( $_POST['keys'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['keys'] ) ) : array();
		$n    = DSMKC_Tools::apply_detected( $keys );
		/* translators: %d: campos. */
		$this->back( 'herramientas', 'imported', trim( sprintf( __( '%d campos aplicados.', 'dsmk-legal' ), $n ) . ' ' . self::created_pages_info() ) );
	}

	/**
	 * AJAX: resultados del escaneo en el navegador.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_browser_scan() {
		check_ajax_referer( 'dsmkc_browser_scan', 'nonce' );
		if ( ! current_user_can( self::cap() ) ) {
			wp_send_json_error( null, 403 );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON saneado en DSMKC_Scanner::browser_results().
		$raw     = isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '';
		$payload = json_decode( (string) $raw, true );
		if ( ! is_array( $payload ) ) {
			wp_send_json_error( null, 400 );
		}
		$browser = DSMKC_Scanner::browser_results( $payload );
		$server  = DSMKC_Scanner::server_scan( false );
		wp_send_json_success(
			array(
				'new_services' => array_values( array_unique( array_merge( $browser['new_services'], $server['new_services'] ) ) ),
				'new_unknown'  => array_values( array_unique( array_merge( $browser['new_unknown'], $server['new_unknown'] ) ) ),
				'bumped'       => $browser['bumped'] || $server['bumped'],
			)
		);
	}
}
