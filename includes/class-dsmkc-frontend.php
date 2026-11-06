<?php
/**
 * DSMK Legal — Front-end: cabecera, integraciones, banner y estilos.
 *
 * @package   DSMK_Legal
 * @file      includes/class-dsmkc-frontend.php
 * @since     1.0.0
 * @author    DSMK Agencia Digital <hola@dsmk.es>
 * @license   GPL-2.0-or-later
 *
 * Responsabilidad:
 *   1. Imprimir lo primero del <head> el script de arranque (dsmkc-head.js):
 *      Consent Mode v2 por defecto denegado + interceptor de scripts/iframes
 *      dinámicos + lectura temprana del consentimiento guardado.
 *   2. Insertar las integraciones configuradas (GA4, GTM, Ads, Pixel, Clarity)
 *      ya bloqueadas hasta el consentimiento.
 *   3. Pintar el banner (1ª capa), el panel (2ª capa) y el botón flotante.
 *      El HTML se imprime en el servidor para que plugins de traducción como
 *      TranslatePress o WPML lo traduzcan, y queda oculto hasta que el JS
 *      decide si mostrarlo (compatible con caché de página).
 *   4. Generar las variables CSS (modo heredar Elementor/tema o personalizado).
 *   5. Integración con WP Consent API.
 *
 * Seguridad:
 *   - Toda salida escapada (esc_html, esc_attr, esc_url, wp_json_encode).
 *   - Los IDs de integraciones se validan con patrón al guardar.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Salida en el front-end.
 *
 * @since 1.0.0
 */
final class DSMKC_Frontend {

	/**
	 * Registra los hooks.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function hooks() {
		add_action( 'wp_head', array( $this, 'print_head' ), -1000 );
		add_action( 'wp_head', array( $this, 'print_integrations' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_footer', array( $this, 'print_banner' ), 5 );
		add_action( 'wp_footer', array( $this, 'print_footer_links' ), 4 );

		// WP Consent API: declaramos que este plugin gestiona el consentimiento (modelo opt-in).
		if ( DSMKC_Settings::get( 'wp_consent_api' ) ) {
			add_filter( 'wp_get_consent_type', array( __CLASS__, 'consent_type' ), 20 );
			add_filter( 'wp_consent_api_registered_' . plugin_basename( DSMKC_FILE ), '__return_true' );
		}
	}

	/**
	 * Tipo de consentimiento para WP Consent API: opt-in (UE).
	 *
	 * @since  1.0.0
	 * @return string
	 */
	public static function consent_type() {
		return 'optin';
	}

	/**
	 * ¿Se debe imprimir algo del plugin en esta petición?
	 *
	 * @since  1.0.0
	 * @return bool
	 */
	private function enabled() {
		return ! DSMKC_Blocker::bypass();
	}

	/**
	 * Configuración que necesita el script de cabecera.
	 *
	 * @since  1.0.0
	 * @return array
	 */
	public static function head_config() {
		$rules = array();
		foreach ( DSMKC_Services::rules() as $rule ) {
			$patterns = array_values( array_unique( array_merge( $rule['src'], $rule['iframe'] ) ) );
			if ( $patterns || $rule['img'] ) {
				// p = script/iframe; i = píxeles <img> (solo sus patrones, para no ocultar imágenes normales).
				$rules[] = array( 's' => $rule['s'], 'c' => $rule['c'], 'p' => $patterns, 'i' => array_values( $rule['img'] ) );
			}
		}
		$data = DSMKC_Settings::data();
		return array(
			'cookie' => 'dsmkc_consent',
			'v'      => (int) $data['policy_version'],
			'days'   => (int) DSMKC_Settings::get( 'consent_dias', 365 ),
			'rules'  => $rules,
			'cm'     => array(
				'on'        => (bool) DSMKC_Settings::get( 'consent_mode' ),
				'redact'    => (bool) DSMKC_Settings::get( 'ads_data_redaction' ),
				'passthru'  => (bool) DSMKC_Settings::get( 'url_passthrough' ),
			),
			'gpc'    => (bool) DSMKC_Settings::get( 'gpc' ),
		);
	}

	/**
	 * Imprime el script de arranque en el <head> (antes que cualquier otro).
	 *
	 * Se imprime en línea (no como fichero) para garantizar que se ejecuta antes
	 * que cualquier etiqueta de seguimiento del tema u otros plugins.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function print_head() {
		if ( ! $this->enabled() ) {
			return;
		}
		$js = (string) file_get_contents( DSMKC_DIR . 'assets/js/dsmkc-head.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Fichero local del plugin.
		$js = (string) preg_replace( '#^/\*\*.*?\*/\s*#s', '', $js ); // Quita la cabecera de documentación (peso de la página).
		echo "\n<!-- DSMK Legal -->\n";
		echo '<script id="dsmkc-head" data-dsmkc-ignore data-cfasync="false" data-no-optimize="1" data-no-defer="1" data-pagespeed-no-defer>';
		echo 'window.dsmkcHead=' . wp_json_encode( self::head_config() ) . ';'; // JSON seguro: wp_json_encode escapa </script>.
		echo $js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Código propio del plugin, sin datos de usuario.
		echo "</script>\n";
	}

	/**
	 * Inserta las integraciones configuradas, ya bloqueadas.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function print_integrations() {
		if ( ! $this->enabled() ) {
			return;
		}
		$advanced = (bool) DSMKC_Settings::get( 'consent_mode_avanzado' ) && (bool) DSMKC_Settings::get( 'consent_mode' );
		$ga4      = (string) DSMKC_Settings::get( 'ga4_id' );
		$ads      = (string) DSMKC_Settings::get( 'ads_id' );
		$gtm      = (string) DSMKC_Settings::get( 'gtm_id' );
		$pixel    = (string) DSMKC_Settings::get( 'pixel_id' );
		$clarity  = (string) DSMKC_Settings::get( 'clarity_id' );

		$gtag_ids = array_filter( array( $ga4, $ads ) );
		if ( $gtag_ids ) {
			if ( $ga4 ) {
				$this->print_loader( 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $ga4 ), 'analytics', 'ga4', $advanced );
			}
			if ( $ads ) {
				$this->print_loader( 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $ads ), 'marketing', 'google_ads', $advanced );
			}
			// La configuración solo añade órdenes al dataLayer: es inerte sin el cargador.
			echo '<script id="dsmkc-gtag-config" data-dsmkc-ignore>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("js",new Date());';
			foreach ( $gtag_ids as $id ) {
				echo 'gtag("config",' . wp_json_encode( $id ) . ');';
			}
			echo "</script>\n";
		}

		if ( $gtm ) {
			$snippet = "(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer'," . wp_json_encode( $gtm ) . ');';
			$this->print_inline( $snippet, DSMKC_Services::category_of( 'gtm' ), 'gtm', $advanced );
		}

		if ( $pixel ) {
			$snippet = "!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init'," . wp_json_encode( $pixel ) . ");fbq('track','PageView');";
			$this->print_inline( $snippet, 'marketing', 'meta_pixel', false );
		}

		if ( $clarity ) {
			$snippet = '(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);})(window,document,"clarity","script",' . wp_json_encode( $clarity ) . ');';
			$this->print_inline( $snippet, 'analytics', 'clarity', false );
		}
	}

	/**
	 * Imprime un cargador externo bloqueado (o libre en Consent Mode avanzado).
	 *
	 * @since  1.0.0
	 * @param  string $url      URL del script.
	 * @param  string $category Categoría.
	 * @param  string $service  Id del servicio.
	 * @param  bool   $free     true = no bloquear (Consent Mode avanzado).
	 * @return void
	 */
	private function print_loader( $url, $category, $service, $free ) {
		if ( $free ) {
			printf( '<script async src="%s" data-dsmkc-ignore></script>' . "\n", esc_url( $url ) );
			return;
		}
		printf(
			'<script type="text/plain" async data-dsmkc-category="%1$s" data-dsmkc-service="%2$s" data-dsmkc-src="%3$s"></script>' . "\n",
			esc_attr( $category ),
			esc_attr( $service ),
			esc_url( $url )
		);
	}

	/**
	 * Imprime un script en línea bloqueado.
	 *
	 * @since  1.0.0
	 * @param  string $code     Código JS (generado por el plugin, IDs validados).
	 * @param  string $category Categoría.
	 * @param  string $service  Id del servicio.
	 * @param  bool   $free     true = no bloquear.
	 * @return void
	 */
	private function print_inline( $code, $category, $service, $free ) {
		if ( $free ) {
			echo '<script data-dsmkc-ignore>' . $code . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Código propio con IDs validados y codificados con wp_json_encode.
			return;
		}
		printf(
			'<script type="text/plain" data-dsmkc-category="%1$s" data-dsmkc-service="%2$s">%3$s</script>' . "\n",
			esc_attr( $category ),
			esc_attr( $service ),
			$code // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ídem.
		);
	}

	/**
	 * Encola el script principal y los estilos.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function enqueue() {
		if ( ! $this->enabled() ) {
			return;
		}
		wp_enqueue_style( 'dsmkc', DSMKC_URL . 'assets/css/dsmkc-banner.css', array(), DSMKC_VERSION );
		wp_add_inline_style( 'dsmkc', self::css_vars() );

		wp_enqueue_script( 'dsmkc', DSMKC_URL . 'assets/js/dsmkc-consent.js', array(), DSMKC_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_add_inline_script( 'dsmkc', 'window.dsmkcConfig=' . wp_json_encode( self::js_config() ) . ';', 'before' );
	}

	/**
	 * Configuración para dsmkc-consent.js.
	 *
	 * @since  1.0.0
	 * @return array
	 */
	public static function js_config() {
		$labels   = DSMKC_Services::category_labels();
		$by_cat   = DSMKC_Services::by_category();
		$cats     = array();
		foreach ( $by_cat as $cat => $list ) {
			if ( 'necessary' !== $cat ) {
				$cats[] = $cat;
			}
		}

		// Nombres de cookies por categoría (de TODO el catálogo) para borrarlas al revocar.
		$delete = array( 'preferences' => array(), 'analytics' => array(), 'marketing' => array() );
		$names  = array();
		foreach ( DSMKC_Services::catalogue() as $id => $service ) {
			$cat = DSMKC_Services::category_of( $id );
			if ( isset( $delete[ $cat ] ) ) {
				foreach ( $service['cookies'] as $cookie ) {
					if ( 'propia' === $cookie[3] ) {
						$delete[ $cat ][] = $cookie[0];
					}
				}
				$names[ $id ] = $service['name'];
			}
		}
		foreach ( DSMKC_Settings::data()['manual'] as $row ) {
			if ( isset( $delete[ $row['category'] ] ) && 'propia' === $row['party'] ) {
				$delete[ $row['category'] ][] = $row['name'];
			}
		}
		foreach ( DSMKC_Services::extra_rules() as $rule ) {
			$names[ $rule['service'] ] = $rule['name'];
		}
		foreach ( $delete as $cat => $list ) {
			$delete[ $cat ] = array_values( array_unique( $list ) );
		}

		$data = DSMKC_Settings::data();
		return array(
			'cookie'    => 'dsmkc_consent',
			'v'         => (int) $data['policy_version'],
			'days'      => (int) DSMKC_Settings::get( 'consent_dias', 365 ),
			'cats'      => $cats,
			'catNames'  => wp_list_pluck( $labels, 'name' ),
			'names'     => $names,
			'del'       => $delete,
			'log'       => (bool) DSMKC_Settings::get( 'log_activo' ),
			// Ruta relativa: el registro funciona aunque la web se sirva con www / sin www o con un dominio alias.
			'rest'      => wp_make_link_relative( esc_url_raw( rest_url( 'dsmkc/v1/consent' ) ) ),
			'bh'        => DSMKC_Legal::banner_hash(),
			'gpc'       => (bool) DSMKC_Settings::get( 'gpc' ),
			'cm'        => (bool) DSMKC_Settings::get( 'consent_mode' ),
			'wpca'      => (bool) DSMKC_Settings::get( 'wp_consent_api' ),
			'policyUrl' => DSMKC_Legal::page_url( 'cookies' ),
			't'         => array(
				/* translators: %s: nombre del servicio (YouTube, Google Maps...). */
				'blocked'  => __( 'Este contenido de %s está bloqueado porque instala cookies de terceros.', 'dsmk-legal' ),
				'load'     => __( 'Aceptar y mostrar', 'dsmk-legal' ),
				'settings' => __( 'Configurar cookies', 'dsmk-legal' ),
				'saved'    => __( 'Preferencias guardadas', 'dsmk-legal' ),
			),
		);
	}

	/**
	 * Variables CSS según el modo de estilo.
	 *
	 * Modo heredar: usa las variables globales de Elementor (kit activo) con
	 * respaldo a las de theme.json (temas de bloques) y, por último, valores
	 * neutros. Modo personalizado: valores del formulario.
	 *
	 * @since  1.0.0
	 * @return string CSS.
	 */
	public static function css_vars() {
		$s       = DSMKC_Settings::all();
		$inherit = 'heredar' === $s['estilo_modo'];

		if ( $inherit ) {
			$vars = array(
				'--dsmkc-bg'           => 'var(--wp--preset--color--base, #ffffff)',
				'--dsmkc-text'         => 'var(--e-global-color-text, var(--wp--preset--color--contrast, #1f2937))',
				'--dsmkc-primary'      => 'var(--e-global-color-primary, var(--wp--preset--color--primary, #1f2937))',
				'--dsmkc-primary-text' => '#ffffff',
				'--dsmkc-link'         => 'var(--e-global-color-accent, var(--e-global-color-primary, #1d4ed8))',
				'--dsmkc-font'         => 'var(--e-global-typography-text-font-family, inherit)',
			);
		} else {
			$vars = array(
				'--dsmkc-bg'           => $s['color_fondo'],
				'--dsmkc-text'         => $s['color_texto'],
				'--dsmkc-primary'      => $s['color_primario'],
				'--dsmkc-primary-text' => $s['color_texto_primario'],
				'--dsmkc-link'         => $s['color_enlace'],
				'--dsmkc-font'         => 'inherit',
			);
		}
		if ( '' !== trim( (string) $s['fuente'] ) ) {
			$vars['--dsmkc-font'] = preg_replace( '/[^a-zA-Z0-9 ,\'"\-]/', '', (string) $s['fuente'] );
		}
		$vars['--dsmkc-radius'] = absint( $s['radio'] ) . 'px';
		$vars['--dsmkc-size']   = absint( $s['tamano_fuente'] ) . 'px';
		$vars['--dsmkc-maxw']   = absint( $s['ancho_max'] ) . 'px';
		$vars['--dsmkc-shadow'] = $s['sombra'] ? '0 10px 40px rgba(0,0,0,.18)' : 'none';

		$css = '.dsmkc-root,.dsmkc-placeholder{';
		foreach ( $vars as $name => $value ) {
			$css .= $name . ':' . $value . ';';
		}
		$css .= '}';
		if ( '' !== $s['css_extra'] ) {
			$css .= "\n" . DSMKC_Settings::sanitize_css( $s['css_extra'] );
		}
		return $css;
	}

	/**
	 * Pinta el banner, el panel de configuración y el botón flotante.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function print_banner() {
		if ( ! $this->enabled() ) {
			return;
		}
		echo self::banner_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escapado dentro de banner_markup().
	}

	/**
	 * Marcado HTML completo del banner (también se usa en la vista previa del admin).
	 *
	 * @since  1.0.0
	 * @param  bool $preview true = visible y sin atributos hidden (vista previa).
	 * @return string HTML escapado.
	 */
	public static function banner_markup( $preview = false ) {
		$s        = DSMKC_Settings::all();
		$t        = DSMKC_Legal::banner_texts();
		$labels   = DSMKC_Services::category_labels();
		$by_cat   = DSMKC_Services::by_category();
		$rows     = DSMKC_Services::cookie_rows();
		$hidden   = $preview ? '' : ' hidden';
		$position = sanitize_html_class( $s['banner_posicion'] );

		ob_start();
		?>
<div id="dsmkc-root" class="dsmkc-root dsmkc-pos-<?php echo esc_attr( $position ); ?>" data-nosnippet>
	<div class="dsmkc-banner" role="dialog" aria-modal="<?php echo 'modal' === $position ? 'true' : 'false'; ?>" aria-labelledby="dsmkc-title" aria-describedby="dsmkc-desc"<?php echo $hidden; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<p class="dsmkc-title" id="dsmkc-title"><?php echo esc_html( $t['title'] ); ?></p>
		<div class="dsmkc-desc" id="dsmkc-desc"><?php echo wp_kses_post( $t['text'] ); ?></div>
		<div class="dsmkc-actions">
			<button type="button" class="dsmkc-btn dsmkc-btn--primary" data-dsmkc-action="accept"><?php echo esc_html( $t['accept'] ); ?></button>
			<button type="button" class="dsmkc-btn dsmkc-btn--primary" data-dsmkc-action="reject"><?php echo esc_html( $t['reject'] ); ?></button>
			<button type="button" class="dsmkc-btn dsmkc-btn--secondary" data-dsmkc-action="settings" aria-haspopup="dialog"><?php echo esc_html( $t['settings'] ); ?></button>
		</div>
	</div>
	<div class="dsmkc-overlay"<?php echo $preview ? ' hidden' : $hidden; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></div>
	<div class="dsmkc-panel" role="dialog" aria-modal="true" aria-labelledby="dsmkc-panel-title" hidden>
		<div class="dsmkc-panel-head">
			<p class="dsmkc-title" id="dsmkc-panel-title"><?php esc_html_e( 'Configuración de cookies', 'dsmk-legal' ); ?></p>
			<button type="button" class="dsmkc-close" data-dsmkc-action="close" aria-label="<?php esc_attr_e( 'Cerrar', 'dsmk-legal' ); ?>">&times;</button>
		</div>
		<div class="dsmkc-panel-body">
			<p class="dsmkc-panel-intro"><?php echo wp_kses_post( $t['panel_intro'] ); ?></p>
			<?php foreach ( $by_cat as $cat => $services ) : ?>
				<?php
				$cat_rows = array_filter(
					$rows,
					static function ( $row ) use ( $cat ) {
						return $row['category'] === $cat;
					}
				);
				$names    = array_unique( wp_list_pluck( $services, 'name' ) );
				?>
				<section class="dsmkc-cat" data-dsmkc-cat="<?php echo esc_attr( $cat ); ?>">
					<div class="dsmkc-cat-head">
						<span class="dsmkc-cat-name" id="dsmkc-cat-<?php echo esc_attr( $cat ); ?>"><?php echo esc_html( $labels[ $cat ]['name'] ); ?></span>
						<?php if ( 'necessary' === $cat ) : ?>
							<span class="dsmkc-always"><?php esc_html_e( 'Siempre activas', 'dsmk-legal' ); ?></span>
						<?php else : ?>
							<label class="dsmkc-switch">
								<input type="checkbox" role="switch" data-dsmkc-toggle="<?php echo esc_attr( $cat ); ?>" aria-labelledby="dsmkc-cat-<?php echo esc_attr( $cat ); ?>">
								<span class="dsmkc-slider" aria-hidden="true"></span>
							</label>
						<?php endif; ?>
					</div>
					<p class="dsmkc-cat-desc"><?php echo esc_html( $labels[ $cat ]['desc'] ); ?></p>
					<?php if ( $names ) : ?>
						<p class="dsmkc-cat-services"><strong><?php esc_html_e( 'Servicios:', 'dsmk-legal' ); ?></strong> <?php echo esc_html( implode( ', ', $names ) ); ?></p>
					<?php endif; ?>
					<?php if ( $cat_rows ) : ?>
						<details class="dsmkc-details">
							<summary>
								<?php
								/* translators: %d: número de cookies. */
								echo esc_html( sprintf( _n( 'Ver %d cookie', 'Ver %d cookies', count( $cat_rows ), 'dsmk-legal' ), count( $cat_rows ) ) );
								?>
							</summary>
							<div class="dsmkc-table-wrap">
								<table class="dsmkc-table">
									<thead><tr><th><?php esc_html_e( 'Cookie', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Titular', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Finalidad', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Duración', 'dsmk-legal' ); ?></th></tr></thead>
									<tbody>
									<?php foreach ( $cat_rows as $row ) : ?>
										<tr><td><code><?php echo esc_html( $row['name'] ); ?></code></td><td><?php echo esc_html( $row['party'] . ( 'Propia' !== $row['provider'] ? ' · ' . $row['provider'] : '' ) ); ?></td><td><?php echo esc_html( $row['purpose'] ); ?></td><td><?php echo esc_html( $row['duration'] ); ?></td></tr>
									<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						</details>
					<?php endif; ?>
				</section>
			<?php endforeach; ?>
		</div>
		<div class="dsmkc-panel-foot">
			<button type="button" class="dsmkc-btn dsmkc-btn--primary" data-dsmkc-action="accept"><?php echo esc_html( $t['accept'] ); ?></button>
			<button type="button" class="dsmkc-btn dsmkc-btn--primary" data-dsmkc-action="reject"><?php echo esc_html( $t['reject'] ); ?></button>
			<button type="button" class="dsmkc-btn dsmkc-btn--secondary" data-dsmkc-action="save"><?php echo esc_html( $t['save'] ); ?></button>
		</div>
	</div>
	<?php if ( ! empty( $s['revisit_mostrar'] ) ) : ?>
	<button type="button" class="dsmkc-revisit dsmkc-revisit--<?php echo esc_attr( sanitize_html_class( $s['revisit_posicion'] ) ); ?>" data-dsmkc-action="settings" aria-label="<?php esc_attr_e( 'Configurar cookies', 'dsmk-legal' ); ?>" title="<?php esc_attr_e( 'Configurar cookies', 'dsmk-legal' ); ?>" hidden>
		<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5Zm-3.5 7A1.5 1.5 0 1 1 7 10.5 1.5 1.5 0 0 1 8.5 9Zm-1 6A1.5 1.5 0 1 1 6 16.5 1.5 1.5 0 0 1 7.5 15Zm5.5 2a1 1 0 1 1-1 1 1 1 0 0 1 1-1Zm4-3a1.5 1.5 0 1 1-1.5 1.5A1.5 1.5 0 0 1 17 14Zm-5-2a1 1 0 1 1-1 1 1 1 0 0 1 1-1Z"/></svg>
	</button>
	<?php endif; ?>
</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Enlaces legales automáticos al final de la página (opcional).
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function print_footer_links() {
		if ( ! $this->enabled() || ! DSMKC_Settings::get( 'enlaces_footer' ) ) {
			return;
		}
		echo '<div class="dsmkc-footer-links">' . DSMKC_Legal::links_markup() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escapado en links_markup().
	}
}
