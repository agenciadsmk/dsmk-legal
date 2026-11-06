<?php
/**
 * DSMK Legal — Comprobaciones e informe técnico de cumplimiento.
 *
 * @package   DSMK_Legal
 * @file      includes/class-dsmkc-report.php
 * @since     1.0.0
 * @author    DSMK Agencia Digital <hola@dsmk.es>
 * @license   GPL-2.0-or-later
 *
 * Responsabilidad:
 *   - checks(): comprobaciones de configuración (titular, documentos, banner,
 *     inventario de cookies, registro, Google, tienda), agrupadas por área.
 *     Las comprobaciones que requieren pedir la web las añade DSMKC_Audit.
 *   - render_printable(): informe técnico completo de una auditoría del
 *     histórico, imprimible a PDF: puntuación, acciones recomendadas,
 *     comprobaciones por área, escaneo por página, servicios y terceros,
 *     inventario de cookies, banner, registro de consentimientos, documentos,
 *     entorno técnico, histórico de auditorías y declaración de cumplimiento.
 *   - Universal: sirve ante cualquier cliente, auditor u organismo; no está
 *     ligado a ningún programa de ayudas concreto.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Informe de cumplimiento.
 *
 * @since 1.0.0
 */
final class DSMKC_Report {

	/**
	 * Ejecuta todas las comprobaciones.
	 *
	 * @since  1.0.0
	 * @param  bool $live true = incluye la petición real a la portada (más lento).
	 * @return array<int, array{id:string, label:string, status:string, detail:string, req:string}>
	 *         status: ok | warn | fail. req: requisito al que responde.
	 */
	public static function checks( $live = true ) {
		$s      = DSMKC_Settings::all();
		$data   = DSMKC_Settings::data();
		$rows   = DSMKC_Services::cookie_rows();
		$checks = array();

		// 1. Datos del titular (aviso legal, art. 10 LSSI).
		$missing = array();
		foreach ( array( 'titular_nombre' => __( 'titular', 'dsmk-legal' ), 'titular_nif' => 'NIF', 'titular_domicilio' => __( 'domicilio', 'dsmk-legal' ), 'titular_email' => 'email' ) as $key => $label ) {
			if ( '' === trim( (string) $s[ $key ] ) ) {
				$missing[] = $label;
			}
		}
		$checks[] = array(
			'id'     => 'titular',
			'label'  => __( 'Datos identificativos del titular completos', 'dsmk-legal' ),
			'status' => $missing ? 'fail' : 'ok',
			/* translators: %s: datos que faltan. */
			'detail' => $missing ? sprintf( __( 'Faltan: %s.', 'dsmk-legal' ), implode( ', ', $missing ) ) : $s['titular_nombre'] . ' · ' . $s['titular_nif'],
			'req'    => 'LSSI art. 10',
		);

		// 2. Documentos legales activos publicados.
		$norms = array( 'aviso' => 'LSSI art. 10', 'privacidad' => 'RGPD art. 13', 'cookies' => 'LSSI art. 22.2 · AEPD', 'terminos' => 'Ley 7/1998 · TRLGDCU', 'condiciones' => 'LSSI art. 27 · TRLGDCU', 'desistimiento' => 'TRLGDCU arts. 102-108 · Dir. 2023/2673' );
		foreach ( DSMKC_Documents::enabled() as $type => $doc ) {
			$url      = DSMKC_Legal::page_url( $type );
			$page_id  = DSMKC_Documents::assigned_page( $type );
			if ( $url && $page_id && 'cookies' !== $type && ! DSMKC_Documents::page_has_shortcode( $page_id, $doc['shortcode'] ) ) {
				$checks[] = array(
					'id'     => 'page_' . $type,
					/* translators: %s: documento. */
					'label'  => sprintf( __( 'Página publicada: %s', 'dsmk-legal' ), $doc['title'] ),
					'status' => 'warn',
					/* translators: %s: URL. */
					'detail' => sprintf( __( '%s muestra su propio texto (no usa el shortcode): revísalo o sustitúyelo en Documentos.', 'dsmk-legal' ), $url ),
					'req'    => isset( $norms[ $type ] ) ? $norms[ $type ] : '',
				);
				continue;
			}
			$checks[] = array(
				'id'     => 'page_' . $type,
				/* translators: %s: documento. */
				'label'  => 'cookies' === $type ? __( 'Apartado específico de política de cookies', 'dsmk-legal' ) : sprintf( __( 'Página publicada: %s', 'dsmk-legal' ), $doc['title'] ),
				'status' => $url ? 'ok' : ( ! empty( $doc['external'] ) ? 'warn' : 'fail' ),
				'detail' => $url ? $url : ( ! empty( $doc['external'] ) ? __( 'Gestionado por otro plugin: asigna su página en Documentos.', 'dsmk-legal' ) : __( 'No hay página asignada: asígnala o créala en Documentos.', 'dsmk-legal' ) ),
				'req'    => isset( $norms[ $type ] ) ? $norms[ $type ] : '',
			);
		}

		// 2b. Tienda online.
		if ( class_exists( 'WooCommerce' ) ) {
			$terms    = (int) get_option( 'woocommerce_terms_page_id' );
			$checks[] = array(
				'id'     => 'woo_terms',
				'label'  => __( 'Tienda: condiciones de venta aceptadas en el checkout', 'dsmk-legal' ),
				'status' => $s['doc_condiciones'] && $terms && $terms === (int) $s['pagina_condiciones'] ? 'ok' : 'fail',
				'detail' => $s['doc_condiciones'] ? __( 'Página asignada como «Términos y condiciones» de WooCommerce.', 'dsmk-legal' ) : __( 'Activa «Condiciones generales de venta» en Documentos.', 'dsmk-legal' ),
				'req'    => 'LSSI art. 27 · TRLGDCU art. 60',
			);
			$external = '' !== (string) apply_filters( 'dsmkc_withdrawal_form', '' );
			$checks[] = array(
				'id'     => 'withdrawal_fn',
				'label'  => __( 'Tienda: función de desistimiento online', 'dsmk-legal' ),
				'status' => $external || 'externo' === $s['desist_modo'] ? 'ok' : 'warn',
				'detail' => $external || 'externo' === $s['desist_modo'] ? __( 'Aportada por el plugin de desistimiento.', 'dsmk-legal' ) : __( 'Solo hay botón por email. Para la Directiva (UE) 2023/2673 instala el plugin de desistimiento de DSMK.', 'dsmk-legal' ),
				'req'    => 'Dir. (UE) 2023/2673',
			);
		}

		// 3. Banner con aceptar y rechazar en la primera capa, mismo nivel.
		$t        = DSMKC_Legal::banner_texts();
		$checks[] = array(
			'id'     => 'banner',
			'label'  => __( 'Banner con botones «Aceptar» y «Rechazar» en la primera capa y con el mismo diseño', 'dsmk-legal' ),
			'status' => 'ok',
			/* translators: 1: aceptar, 2: rechazar, 3: configurar. */
			'detail' => sprintf( __( 'Botones: «%1$s», «%2$s» y «%3$s». Aceptar y rechazar comparten estilo (no configurable, por diseño).', 'dsmk-legal' ), $t['accept'], $t['reject'], $t['settings'] ),
			'req'    => 'LSSI art. 22.2 · AEPD',
		);


		// 5. Tabla de cookies completa.
		$incomplete = array();
		foreach ( $rows as $row ) {
			if ( '' === trim( $row['purpose'] ) || '' === trim( $row['duration'] ) || '' === trim( $row['party'] ) ) {
				$incomplete[] = $row['name'];
			}
		}
		$checks[] = array(
			'id'     => 'table',
			'label'  => __( 'Relación de cookies con tipo, finalidad, titular (propia / de terceros) y plazo de conservación', 'dsmk-legal' ),
			'status' => $incomplete ? 'fail' : 'ok',
			/* translators: %d: número de cookies. */
			'detail' => $incomplete ? sprintf( __( 'Cookies sin datos completos: %s.', 'dsmk-legal' ), implode( ', ', $incomplete ) ) : sprintf( __( '%d cookies documentadas automáticamente.', 'dsmk-legal' ), count( $rows ) ),
			'req'    => 'LSSI art. 22.2',
		);

		// 6. Contenidos de la política (definición, tipos, revocación): generados siempre por el shortcode.
		$policy_id = (int) $s['pagina_cookies'];
		$auto      = $policy_id && DSMKC_Documents::page_has_shortcode( $policy_id, 'dsmk_politica_cookies' );
		$checks[]  = array(
			'id'     => 'policy_content',
			'label'  => __( 'Política con definición y función de las cookies, clasificación según origen, finalidad y plazo, y forma de aceptar, denegar o revocar', 'dsmk-legal' ),
			'status' => $auto ? 'ok' : ( $policy_id ? 'warn' : 'fail' ),
			'detail' => $auto ? __( 'Generada por [dsmk_politica_cookies]: incluye todos los apartados y se actualiza sola.', 'dsmk-legal' ) : __( 'La página no usa el shortcode [dsmk_politica_cookies]: revisa a mano que incluya todos los apartados.', 'dsmk-legal' ),
			'req'    => 'LSSI art. 22.2',
		);

		// 7. Revocación en cualquier momento.
		$checks[] = array(
			'id'     => 'revoke',
			'label'  => __( 'Retirada del consentimiento en cualquier momento, tan fácil como darlo', 'dsmk-legal' ),
			'status' => $s['revisit_mostrar'] || $auto ? 'ok' : 'warn',
			'detail' => $s['revisit_mostrar'] ? __( 'Botón flotante permanente + enlace en la política. Al retirar se borran las cookies y se recarga.', 'dsmk-legal' ) : __( 'Botón flotante desactivado: asegúrate de enlazar «#dsmk-cookies» en el pie de todas las páginas.', 'dsmk-legal' ),
			'req'    => 'LSSI art. 22.2 · RGPD art. 7.3',
		);

		// 8. Cookies sin clasificar.
		$unclassified = array_keys( (array) $data['unknown'] );
		$checks[]     = array(
			'id'     => 'unknown',
			'label'  => __( 'Todas las cookies detectadas están clasificadas', 'dsmk-legal' ),
			'status' => $unclassified ? 'warn' : 'ok',
			/* translators: %s: lista. */
			'detail' => $unclassified ? sprintf( __( 'Pendientes de clasificar: %s.', 'dsmk-legal' ), implode( ', ', array_slice( $unclassified, 0, 15 ) ) ) : __( 'Sin elementos pendientes.', 'dsmk-legal' ),
			'req'    => 'AEPD',
		);

		// 9. Escaneo reciente.
		$last     = (int) $data['last_scan'];
		$checks[] = array(
			'id'     => 'scan',
			'label'  => __( 'Escaneo de cookies reciente (menos de 8 días)', 'dsmk-legal' ),
			'status' => $last && ( time() - $last ) < 8 * DAY_IN_SECONDS ? 'ok' : 'warn',
			'detail' => $last ? wp_date( 'd/m/Y H:i', $last ) : __( 'Nunca. Ejecuta un escaneo desde «Servicios y cookies».', 'dsmk-legal' ),
			'req'    => 'AEPD',
		);

		// 10. Validez del consentimiento.
		$checks[] = array(
			'id'     => 'expiry',
			'label'  => __( 'Validez del consentimiento no superior a 24 meses', 'dsmk-legal' ),
			'status' => (int) $s['consent_dias'] <= 730 ? 'ok' : 'fail',
			'detail' => DSMKC_Services::consent_duration_label(),
			'req'    => 'AEPD',
		);

		// 11. Registro de consentimientos.
		$checks[] = array(
			'id'     => 'log',
			'label'  => __( 'Registro de pruebas del consentimiento', 'dsmk-legal' ),
			'status' => $s['log_activo'] ? 'ok' : 'warn',
			'detail' => $s['log_activo'] ? __( 'Activo (IP anonimizada).', 'dsmk-legal' ) : __( 'Desactivado: no podrás demostrar los consentimientos.', 'dsmk-legal' ),
			'req'    => 'RGPD art. 7.1',
		);

		// 12. Google: Consent Mode y AdSense.
		$active = DSMKC_Services::active();
		$google = array_intersect( array_keys( $active ), array( 'ga4', 'gtm', 'google_ads', 'adsense' ) );
		if ( $google ) {
			$checks[] = array(
				'id'     => 'gcm',
				'label'  => __( 'Google Consent Mode v2 activo', 'dsmk-legal' ),
				'status' => $s['consent_mode'] ? 'ok' : 'warn',
				'detail' => $s['consent_mode'] ? __( 'Estado por defecto denegado y actualización al consentir.', 'dsmk-legal' ) : __( 'Desactivado: Google Ads perderá medición en el EEE.', 'dsmk-legal' ),
				'req'    => 'Google (EEE)',
			);
		}
		if ( isset( $active['adsense'] ) ) {
			$checks[] = array(
				'id'     => 'adsense',
				'label'  => __( 'AdSense / Ad Manager detectado', 'dsmk-legal' ),
				'status' => 'warn',
				'detail' => __( 'Google exige un CMP certificado con IAB TCF para mostrar anuncios personalizados en el EEE. Este plugin cumple la ley, pero no es un CMP certificado: AdSense solo servirá anuncios no personalizados.', 'dsmk-legal' ),
				'req'    => 'Google (editores)',
			);
		}

		// Área de cada comprobación (para agrupar el informe).
		$groups = array( 'titular' => 'legal', 'woo_terms' => 'tienda', 'withdrawal_fn' => 'tienda', 'banner' => 'cookies', 'table' => 'cookies', 'policy_content' => 'cookies', 'revoke' => 'cookies', 'unknown' => 'cookies', 'scan' => 'escaneo', 'expiry' => 'cookies', 'log' => 'consentimiento', 'gcm' => 'cookies', 'adsense' => 'cookies' );
		foreach ( $checks as $i => $check ) {
			$checks[ $i ]['group'] = isset( $groups[ $check['id'] ] ) ? $groups[ $check['id'] ] : ( 0 === strpos( $check['id'], 'page_' ) ? 'legal' : 'otros' );
		}
		return $checks;
	}

	/**
	 * Nombres de las áreas del informe, en orden.
	 *
	 * @since  2.1.0
	 * @return array<string, string>
	 */
	public static function groups() {
		return array(
			'legal'          => __( 'Información legal', 'dsmk-legal' ),
			'cookies'        => __( 'Cookies y consentimiento', 'dsmk-legal' ),
			'privacidad'     => __( 'Privacidad y terceros', 'dsmk-legal' ),
			'consentimiento' => __( 'Prueba del consentimiento', 'dsmk-legal' ),
			'tienda'         => __( 'Tienda online', 'dsmk-legal' ),
			'seguridad'      => __( 'Seguridad técnica', 'dsmk-legal' ),
			'escaneo'        => __( 'Escaneo', 'dsmk-legal' ),
			'otros'          => __( 'Otros', 'dsmk-legal' ),
		);
	}

	/**
	 * Resumen numérico de comprobaciones.
	 *
	 * @since  1.0.0
	 * @param  array $checks Comprobaciones.
	 * @return array{ok:int, warn:int, fail:int}
	 */
	public static function summary( array $checks ) {
		$out = array( 'ok' => 0, 'warn' => 0, 'fail' => 0 );
		foreach ( $checks as $check ) {
			++$out[ $check['status'] ];
		}
		return $out;
	}

	/**
	 * Declaración técnica de cumplimiento (texto genérico, reutilizable ante
	 * cualquier organismo, cliente o auditor).
	 *
	 * @since  2.1.0
	 * @param  array|null $record Auditoría de referencia.
	 * @return string Texto plano.
	 */
	public static function declaration_text( $record = null ) {
		$cookies_url = DSMKC_Legal::page_url( 'cookies' );
		$date        = $record ? wp_date( 'd/m/Y', $record['date'] ) : wp_date( 'd/m/Y' );
		return sprintf(
			/* translators: 1: web, 2: URL política de cookies, 3: fecha, 4: ID de auditoría. */
			__( 'En el sitio web %1$s está implantado un sistema de gestión del consentimiento de cookies que: (1) muestra en la primera capa un aviso con las opciones «Aceptar todas», «Rechazar todas» y «Configurar», con el mismo nivel de visibilidad; (2) bloquea técnicamente las cookies no técnicas y de terceros hasta que el usuario otorga su consentimiento expreso, sin instalar ninguna si las rechaza; (3) permite configurar el consentimiento por categorías y aceptarlo, denegarlo o revocarlo en cualquier momento desde un botón permanente y desde la propia política, eliminando las cookies afectadas; (4) conserva prueba de cada consentimiento; y (5) dispone de una política de cookies específica en %2$s con la definición y función de las cookies, su clasificación según origen, finalidad y plazo, la relación de cookies utilizadas con su titular (propia o de terceros), finalidad y plazo de conservación, y la forma de aceptar, denegar o revocar el consentimiento. Se adjunta informe técnico de auditoría de fecha %3$s (ref. %4$s).', 'dsmk-legal' ),
			home_url( '/' ),
			$cookies_url ? $cookies_url : __( '[URL de la política de cookies]', 'dsmk-legal' ),
			$date,
			$record ? $record['id'] : '—'
		);
	}

	/**
	 * Imprime el informe técnico completo de una auditoría (documento HTML
	 * independiente, listo para «Guardar como PDF»).
	 *
	 * Seguridad: solo se llama desde DSMKC_Admin tras comprobar capacidad y nonce.
	 *
	 * @since  1.0.0
	 * @since  2.1.0 Informe técnico basado en una auditoría del histórico.
	 * @param  array $r Auditoría.
	 * @return void
	 */
	public static function render_printable( array $r ) {
		$s       = DSMKC_Settings::all();
		$t       = DSMKC_Legal::banner_texts();
		$stats   = DSMKC_Consent_Log::stats( 30 );
		$monthly = DSMKC_Consent_Log::monthly( 12 );
		$groups  = self::groups();
		$tot     = $r['totals'];
		$env     = isset( $r['env'] ) ? $r['env'] : array();
		$labels  = array( 'ok' => '✔ ' . __( 'Cumple', 'dsmk-legal' ), 'warn' => '! ' . __( 'Revisar', 'dsmk-legal' ), 'fail' => '✘ ' . __( 'No cumple', 'dsmk-legal' ) );
		$catn    = wp_list_pluck( DSMKC_Services::category_labels(), 'name' );
		$catalog = DSMKC_Services::catalogue();
		$issues  = array_filter(
			$r['checks'],
			static function ( $c ) {
				return 'ok' !== $c['status'];
			}
		);
		$yesno   = static function ( $v ) {
			return $v ? __( 'Sí', 'dsmk-legal' ) : __( 'No', 'dsmk-legal' );
		};
		$color   = $r['score'] >= 90 ? '#166534' : ( $r['score'] >= 70 ? '#92400e' : '#b91c1c' );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html( sprintf( 'Informe técnico legal — %s — %s', DSMKC_Legal::domain(), wp_date( 'd-m-Y', $r['date'] ) ) ); ?></title>
<style>
	body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:#1f2937;max-width:960px;margin:32px auto;padding:0 24px;font-size:12.5px;line-height:1.5}
	h1{font-size:24px;margin:0 0 4px}h2{font-size:16px;margin:30px 0 8px;border-bottom:2px solid #1f2937;padding-bottom:4px;page-break-after:avoid}h3{font-size:13.5px;margin:18px 0 6px}
	table{width:100%;border-collapse:collapse;margin:8px 0}th,td{border:1px solid #d1d5db;padding:5px 7px;text-align:left;vertical-align:top}th{background:#f3f4f6}
	.ok{color:#166534}.warn{color:#92400e}.fail{color:#b91c1c}.st{font-weight:700;white-space:nowrap}.muted{color:#6b7280}
	.meta td:first-child{width:230px;font-weight:600;background:#f9fafb}
	.box{border:1px solid #d1d5db;background:#f9fafb;padding:12px;border-radius:6px;white-space:pre-wrap}
	.cover{display:flex;gap:24px;align-items:center;margin:16px 0}.score{width:120px;height:120px;border-radius:50%;border:8px solid <?php echo esc_attr( $color ); ?>;display:flex;align-items:center;justify-content:center;flex-direction:column;font-weight:800;font-size:32px;color:<?php echo esc_attr( $color ); ?>}
	.score small{font-size:11px;font-weight:600;color:#6b7280}.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;flex:1}.kpi{border:1px solid #e5e7eb;border-radius:6px;padding:8px}.kpi b{display:block;font-size:20px}
	.no-print{margin:16px 0}.no-print button{font-size:14px;padding:8px 16px;cursor:pointer}
	code{font-size:11px;word-break:break-all}.small{font-size:11px}
	@media print{.no-print{display:none}body{margin:0;max-width:none}tr{page-break-inside:avoid}}
</style>
</head>
<body>
<div class="no-print"><button onclick="window.print()"><?php esc_html_e( 'Imprimir / Guardar como PDF', 'dsmk-legal' ); ?></button></div>

<h1><?php esc_html_e( 'Informe técnico de cumplimiento legal web', 'dsmk-legal' ); ?></h1>
<p class="muted"><?php esc_html_e( 'LSSI · RGPD / LOPDGDD · Guía sobre el uso de las cookies (AEPD) · TRLGDCU', 'dsmk-legal' ); ?></p>

<div class="cover">
	<div class="score"><?php echo (int) $r['score']; ?><small>/ 100</small></div>
	<div class="kpis">
		<div class="kpi"><b class="ok"><?php echo (int) $r['summary']['ok']; ?></b><?php esc_html_e( 'cumple', 'dsmk-legal' ); ?></div>
		<div class="kpi"><b class="warn"><?php echo (int) $r['summary']['warn']; ?></b><?php esc_html_e( 'revisar', 'dsmk-legal' ); ?></div>
		<div class="kpi"><b class="fail"><?php echo (int) $r['summary']['fail']; ?></b><?php esc_html_e( 'no cumple', 'dsmk-legal' ); ?></div>
		<div class="kpi"><b><?php echo (int) $tot['pages']; ?></b><?php esc_html_e( 'páginas analizadas', 'dsmk-legal' ); ?></div>
		<div class="kpi"><b class="<?php echo $tot['leaks'] ? 'fail' : 'ok'; ?>"><?php echo (int) $tot['leaks']; ?></b><?php esc_html_e( 'rastreadores sin consentimiento', 'dsmk-legal' ); ?></div>
		<div class="kpi"><b class="<?php echo $tot['bad_cookies'] ? 'fail' : 'ok'; ?>"><?php echo (int) $tot['bad_cookies']; ?></b><?php esc_html_e( 'cookies no técnicas sin consentimiento', 'dsmk-legal' ); ?></div>
		<div class="kpi"><b><?php echo count( DSMKC_Services::cookie_rows() ); ?></b><?php esc_html_e( 'cookies documentadas', 'dsmk-legal' ); ?></div>
		<div class="kpi"><b><?php echo count( $tot['external_hosts'] ); ?></b><?php esc_html_e( 'dominios de terceros antes de consentir', 'dsmk-legal' ); ?></div>
	</div>
</div>

<table class="meta">
	<tr><td><?php esc_html_e( 'Sitio web', 'dsmk-legal' ); ?></td><td><?php echo esc_html( home_url( '/' ) ); ?></td></tr>
	<tr><td><?php esc_html_e( 'Titular', 'dsmk-legal' ); ?></td><td><?php echo esc_html( trim( $s['titular_nombre'] . ' · ' . $s['titular_nif'] . ' · ' . $s['titular_domicilio'], ' ·' ) ); ?></td></tr>
	<tr><td><?php esc_html_e( 'Fecha de la auditoría', 'dsmk-legal' ); ?></td><td><?php echo esc_html( wp_date( 'd/m/Y H:i', $r['date'] ) . ' (' . ( 'automatico' === $r['trigger'] ? __( 'automática', 'dsmk-legal' ) : __( 'manual', 'dsmk-legal' ) . ( $r['user'] ? ', ' . $r['user'] : '' ) ) . ', ' . $r['duration'] . ' s)' ); ?></td></tr>
	<tr><td><?php esc_html_e( 'Referencia', 'dsmk-legal' ); ?></td><td><?php echo esc_html( $r['id'] ); ?></td></tr>
	<tr><td><?php esc_html_e( 'Sistema', 'dsmk-legal' ); ?></td><td><?php echo esc_html( 'DSMK Legal ' . $r['plugin_version'] . ' · ' . __( 'política v', 'dsmk-legal' ) . $r['policy_version'] . ' · ' . __( 'banner', 'dsmk-legal' ) . ' ' . $r['banner_hash'] ); ?></td></tr>
</table>

<h2><?php esc_html_e( '1. Resumen y acciones recomendadas', 'dsmk-legal' ); ?></h2>
<?php if ( ! $issues ) : ?>
	<p class="ok"><strong><?php esc_html_e( 'No se han detectado incumplimientos ni puntos a revisar.', 'dsmk-legal' ); ?></strong></p>
<?php else : ?>
	<table><thead><tr><th><?php esc_html_e( 'Prioridad', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Punto', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Detalle / acción', 'dsmk-legal' ); ?></th></tr></thead><tbody>
	<?php foreach ( array( 'fail', 'warn' ) as $level ) : ?>
		<?php foreach ( $issues as $c ) : ?>
			<?php if ( $c['status'] !== $level ) { continue; } ?>
			<tr><td class="st <?php echo esc_attr( $level ); ?>"><?php echo 'fail' === $level ? esc_html__( 'Alta', 'dsmk-legal' ) : esc_html__( 'Media', 'dsmk-legal' ); ?></td><td><?php echo esc_html( $c['label'] ); ?></td><td><?php echo esc_html( wp_strip_all_tags( $c['detail'] ) ); ?></td></tr>
		<?php endforeach; ?>
	<?php endforeach; ?>
	</tbody></table>
<?php endif; ?>

<h2><?php esc_html_e( '2. Comprobaciones por área', 'dsmk-legal' ); ?></h2>
<?php foreach ( $groups as $gkey => $gname ) : ?>
	<?php
	$items = array_filter(
		$r['checks'],
		static function ( $c ) use ( $gkey ) {
			return ( isset( $c['group'] ) ? $c['group'] : 'otros' ) === $gkey;
		}
	);
	if ( ! $items ) {
		continue;
	}
	?>
	<h3><?php echo esc_html( $gname ); ?></h3>
	<table><thead><tr><th style="width:34%"><?php esc_html_e( 'Requisito', 'dsmk-legal' ); ?></th><th style="width:11%"><?php esc_html_e( 'Resultado', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Evidencia', 'dsmk-legal' ); ?></th><th style="width:15%"><?php esc_html_e( 'Norma', 'dsmk-legal' ); ?></th></tr></thead><tbody>
	<?php foreach ( $items as $c ) : ?>
		<tr><td><?php echo esc_html( $c['label'] ); ?></td><td class="st <?php echo esc_attr( $c['status'] ); ?>"><?php echo esc_html( $labels[ $c['status'] ] ); ?></td><td><?php echo esc_html( wp_strip_all_tags( $c['detail'] ) ); ?></td><td class="small"><?php echo esc_html( $c['req'] ); ?></td></tr>
	<?php endforeach; ?>
	</tbody></table>
<?php endforeach; ?>

<h2><?php esc_html_e( '3. Escaneo por página (vista de un visitante sin consentimiento)', 'dsmk-legal' ); ?></h2>
<?php if ( empty( $r['pages'] ) ) : ?>
	<p class="muted"><?php esc_html_e( 'El detalle por página solo se conserva en las 10 auditorías más recientes.', 'dsmk-legal' ); ?></p>
<?php else : ?>
<table class="small"><thead><tr><th><?php esc_html_e( 'URL', 'dsmk-legal' ); ?></th><th>HTTP</th><th>ms</th><th><?php esc_html_e( 'Banner', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Rastreadores sin consentimiento', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Cookies del servidor', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Enlaces legales que faltan', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Formularios', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Servicios presentes', 'dsmk-legal' ); ?></th></tr></thead><tbody>
	<?php foreach ( $r['pages'] as $p ) : ?>
		<tr>
			<td><code><?php echo esc_html( (string) wp_parse_url( $p['url'], PHP_URL_PATH ) ); ?></code></td>
			<td class="<?php echo $p['ok'] && $p['status'] < 400 ? 'ok' : 'fail'; ?>"><?php echo $p['ok'] ? (int) $p['status'] : esc_html( $p['error'] ); ?></td>
			<td><?php echo (int) $p['ms']; ?></td>
			<td class="<?php echo $p['banner'] ? 'ok' : 'fail'; ?>"><?php echo esc_html( $yesno( $p['banner'] ) ); ?></td>
			<td class="<?php echo $p['leaks'] ? 'fail' : 'ok'; ?>"><?php echo $p['leaks'] ? esc_html( implode( ', ', array_unique( wp_list_pluck( $p['leaks'], 'service' ) ) ) ) : '0'; ?></td>
			<td><?php echo $p['cookies'] ? esc_html( implode( ', ', array_map( static function ( $n, $c ) { return $n . ' (' . $c . ')'; }, array_keys( $p['cookies'] ), $p['cookies'] ) ) ) : '—'; ?></td>
			<td class="<?php echo $p['missing_links'] ? 'warn' : 'ok'; ?>"><?php echo $p['missing_links'] ? esc_html( implode( ', ', $p['missing_links'] ) ) : '—'; ?></td>
			<td>
			<?php
			if ( ! $p['forms'] ) {
				echo '—';
			}
			foreach ( $p['forms'] as $f ) {
				echo esc_html( implode( '/', $f['fields'] ) ) . ': <span class="' . ( $f['privacy_link'] || $f['info'] ? 'ok' : 'warn' ) . '">' . esc_html( ( $f['privacy_link'] ? __( 'enlace privacidad', 'dsmk-legal' ) : __( 'sin enlace', 'dsmk-legal' ) ) . ( $f['checkbox'] ? ', ' . __( 'casilla', 'dsmk-legal' ) : '' ) . ( $f['info'] ? ', ' . __( 'info básica', 'dsmk-legal' ) : '' ) ) . '</span><br>';
			}
			?>
			</td>
			<td><?php echo $p['services'] ? esc_html( implode( ', ', array_keys( $p['services'] ) ) ) : '—'; ?></td>
		</tr>
	<?php endforeach; ?>
</tbody></table>
<?php endif; ?>

<h2><?php esc_html_e( '4. Servicios y terceros', 'dsmk-legal' ); ?></h2>
<table class="small"><thead><tr><th><?php esc_html_e( 'Servicio', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Proveedor', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Categoría', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Transferencia internacional', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Tratamiento sin consentimiento', 'dsmk-legal' ); ?></th></tr></thead><tbody>
	<?php foreach ( DSMKC_Services::active() as $sid => $svc ) : ?>
		<tr><td><?php echo esc_html( $svc['name'] ); ?></td><td><?php echo esc_html( $svc['provider'] ); ?></td><td><?php echo esc_html( isset( $catn[ $svc['category'] ] ) ? $catn[ $svc['category'] ] : $svc['category'] ); ?></td><td><?php echo esc_html( $svc['transfer'] ? $svc['transfer'] : '—' ); ?></td><td class="<?php echo 'necessary' === $svc['category'] ? '' : 'ok'; ?>"><?php echo 'necessary' === $svc['category'] ? esc_html__( 'Técnico (exento)', 'dsmk-legal' ) : esc_html__( 'Bloqueado hasta consentimiento', 'dsmk-legal' ); ?></td></tr>
	<?php endforeach; ?>
</tbody></table>
<h3><?php esc_html_e( 'Dominios de terceros cargados antes del consentimiento', 'dsmk-legal' ); ?></h3>
<?php if ( $tot['external_hosts'] ) : ?>
	<table class="small"><thead><tr><th><?php esc_html_e( 'Dominio', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Recursos', 'dsmk-legal' ); ?></th></tr></thead><tbody>
	<?php foreach ( $tot['external_hosts'] as $host => $n ) : ?>
		<tr><td><code><?php echo esc_html( $host ); ?></code></td><td><?php echo (int) $n; ?></td></tr>
	<?php endforeach; ?>
	</tbody></table>
<?php else : ?>
	<p class="ok"><?php esc_html_e( 'Ninguno.', 'dsmk-legal' ); ?></p>
<?php endif; ?>

<h2><?php esc_html_e( '5. Inventario de cookies', 'dsmk-legal' ); ?></h2>
<?php echo DSMKC_Legal::cookie_tables(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escapado en cookie_tables(). ?>
<?php $unknown = (array) DSMKC_Settings::data()['unknown']; ?>
<?php if ( $unknown ) : ?>
	<h3><?php esc_html_e( 'Pendientes de clasificar', 'dsmk-legal' ); ?></h3>
	<p class="warn"><?php echo esc_html( implode( ', ', array_keys( $unknown ) ) ); ?></p>
<?php endif; ?>

<h2><?php esc_html_e( '6. Banner y gestión del consentimiento', 'dsmk-legal' ); ?></h2>
<div class="box"><strong><?php echo esc_html( $t['title'] ); ?></strong>
<?php echo esc_html( trim( wp_strip_all_tags( $t['text'] ) ) ); ?>

[ <?php echo esc_html( $t['accept'] ); ?> ]  [ <?php echo esc_html( $t['reject'] ); ?> ]  [ <?php echo esc_html( $t['settings'] ); ?> ]</div>
<table class="meta small">
	<tr><td><?php esc_html_e( 'Formato', 'dsmk-legal' ); ?></td><td><?php echo esc_html( $s['banner_posicion'] ); ?></td></tr>
	<tr><td><?php esc_html_e( 'Aceptar y rechazar con el mismo diseño', 'dsmk-legal' ); ?></td><td><?php esc_html_e( 'Sí (fijo, no configurable)', 'dsmk-legal' ); ?></td></tr>
	<tr><td><?php esc_html_e( 'Casillas premarcadas', 'dsmk-legal' ); ?></td><td><?php esc_html_e( 'No', 'dsmk-legal' ); ?></td></tr>
	<tr><td><?php esc_html_e( 'Botón permanente para cambiar el consentimiento', 'dsmk-legal' ); ?></td><td><?php echo esc_html( $yesno( $s['revisit_mostrar'] ) ); ?></td></tr>
	<tr><td><?php esc_html_e( 'Validez del consentimiento', 'dsmk-legal' ); ?></td><td><?php echo esc_html( DSMKC_Services::consent_duration_label() ); ?></td></tr>
	<tr><td><?php esc_html_e( 'Revocación', 'dsmk-legal' ); ?></td><td><?php esc_html_e( 'Borra las cookies propias de las categorías retiradas y recarga la página', 'dsmk-legal' ); ?></td></tr>
	<tr><td>Google Consent Mode v2</td><td><?php echo esc_html( $yesno( $s['consent_mode'] ) . ( $s['consent_mode_avanzado'] ? ' (' . __( 'avanzado', 'dsmk-legal' ) . ')' : ' (' . __( 'básico: sin etiquetas antes de consentir', 'dsmk-legal' ) . ')' ) ); ?></td></tr>
	<tr><td>Global Privacy Control</td><td><?php echo esc_html( $yesno( $s['gpc'] ) ); ?></td></tr>
	<tr><td>WP Consent API</td><td><?php echo esc_html( $yesno( $s['wp_consent_api'] ) ); ?></td></tr>
	<tr><td><?php esc_html_e( 'Funcionamiento técnico', 'dsmk-legal' ); ?></td><td><?php esc_html_e( 'Los scripts, iframes y píxeles de servicios no necesarios se entregan desactivados (type="text/plain" / sin src) y solo se activan tras el consentimiento de su categoría; los que se crean por código se interceptan en el navegador.', 'dsmk-legal' ); ?></td></tr>
</table>
<h3><?php esc_html_e( 'Versiones del banner mostradas', 'dsmk-legal' ); ?></h3>
<table class="small"><thead><tr><th><?php esc_html_e( 'Huella', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Desde', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Política', 'dsmk-legal' ); ?></th></tr></thead><tbody>
	<?php foreach ( array_reverse( (array) DSMKC_Settings::data()['banner_history'], true ) as $hash => $item ) : ?>
		<tr><td><code><?php echo esc_html( $hash ); ?></code></td><td><?php echo esc_html( wp_date( 'd/m/Y H:i', $item['date'] ) ); ?></td><td><?php echo (int) $item['version']; ?></td></tr>
	<?php endforeach; ?>
</tbody></table>

<h2><?php esc_html_e( '7. Registro de consentimientos', 'dsmk-legal' ); ?></h2>
<p><?php echo esc_html( sprintf( __( 'Últimos 30 días: %1$d decisiones — aceptar todas %2$d · rechazar todas %3$d · selección personalizada %4$d · contenido puntual %5$d · GPC %6$d.', 'dsmk-legal' ), $stats['total'], $stats['accept'], $stats['reject'], $stats['custom'], $stats['service'], $stats['gpc'] ) ); ?></p>
<?php if ( $monthly ) : ?>
<table class="small"><thead><tr><th><?php esc_html_e( 'Mes', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Total', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Aceptar', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Rechazar', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Personalizado', 'dsmk-legal' ); ?></th><th><?php esc_html_e( '% aceptación', 'dsmk-legal' ); ?></th></tr></thead><tbody>
	<?php foreach ( $monthly as $ym => $m ) : ?>
		<tr><td><?php echo esc_html( $ym ); ?></td><td><?php echo (int) $m['total']; ?></td><td><?php echo (int) $m['accept']; ?></td><td><?php echo (int) $m['reject']; ?></td><td><?php echo (int) $m['custom']; ?></td><td><?php echo $m['total'] ? (int) round( $m['accept'] / $m['total'] * 100 ) . ' %' : '—'; ?></td></tr>
	<?php endforeach; ?>
</tbody></table>
<?php endif; ?>
<p class="small muted"><?php echo esc_html( sprintf( __( 'Cada registro guarda un ID anónimo, fecha, decisión, versión del banner, IP anonimizada y huella del navegador (hash). Conservación: %d meses.', 'dsmk-legal' ), (int) $s['log_meses'] ) ); ?></p>

<h2><?php esc_html_e( '8. Documentos legales', 'dsmk-legal' ); ?></h2>
<table class="small"><thead><tr><th><?php esc_html_e( 'Documento', 'dsmk-legal' ); ?></th><th>URL</th><th><?php esc_html_e( 'Texto generado por el sistema', 'dsmk-legal' ); ?></th></tr></thead><tbody>
	<?php foreach ( DSMKC_Documents::enabled() as $type => $doc ) : ?>
		<?php $pid = DSMKC_Documents::assigned_page( $type ); ?>
		<tr><td><?php echo esc_html( $doc['title'] ); ?></td><td><?php echo esc_html( DSMKC_Legal::page_url( $type ) ? DSMKC_Legal::page_url( $type ) : __( 'sin publicar', 'dsmk-legal' ) ); ?></td><td><?php echo esc_html( $pid ? $yesno( DSMKC_Documents::page_has_shortcode( $pid, $doc['shortcode'] ) ) : '—' ); ?></td></tr>
	<?php endforeach; ?>
</tbody></table>

<?php if ( $env ) : ?>
<h2><?php esc_html_e( '9. Entorno técnico', 'dsmk-legal' ); ?></h2>
<table class="meta small">
	<tr><td>WordPress</td><td><?php echo esc_html( $env['wordpress'] ); ?></td></tr>
	<tr><td>PHP</td><td><?php echo esc_html( $env['php'] ); ?></td></tr>
	<tr><td><?php esc_html_e( 'Servidor', 'dsmk-legal' ); ?></td><td><?php echo esc_html( $env['server'] ? $env['server'] : '—' ); ?></td></tr>
	<tr><td><?php esc_html_e( 'Tema', 'dsmk-legal' ); ?></td><td><?php echo esc_html( $env['theme'] ); ?></td></tr>
	<tr><td><?php esc_html_e( 'Idioma / zona horaria', 'dsmk-legal' ); ?></td><td><?php echo esc_html( $env['locale'] . ' / ' . $env['timezone'] ); ?></td></tr>
	<tr><td>HTTPS</td><td><?php echo esc_html( $yesno( $env['https'] ) ); ?></td></tr>
	<tr><td><?php esc_html_e( 'Certificado SSL', 'dsmk-legal' ); ?></td><td><?php echo esc_html( ! empty( $r['ssl']['valid'] ) ? sprintf( '%s · %s (%d días)', $r['ssl']['issuer'], wp_date( 'd/m/Y', $r['ssl']['valid_to'] ), $r['ssl']['days'] ) : ( ! empty( $r['ssl']['checked'] ) ? __( 'No válido', 'dsmk-legal' ) . ' ' . $r['ssl']['error'] : '—' ) ); ?></td></tr>
	<tr><td><?php esc_html_e( 'Caché', 'dsmk-legal' ); ?></td><td><?php echo esc_html( ( $env['wp_cache'] ? 'WP_CACHE · ' : '' ) . ( $env['cache'] ? implode( ', ', $env['cache'] ) : __( 'sin plugin de caché detectado', 'dsmk-legal' ) ) ); ?></td></tr>
	<tr><td>Elementor / WooCommerce</td><td><?php echo esc_html( ( $env['elementor'] ? $env['elementor'] : '—' ) . ' / ' . ( $env['woocommerce'] ? $env['woocommerce'] : '—' ) ); ?></td></tr>
	<tr><td><?php esc_html_e( 'Tiempo medio de respuesta', 'dsmk-legal' ); ?></td><td><?php echo (int) $tot['avg_ms']; ?> ms</td></tr>
	<?php if ( ! empty( $r['pages'][0]['headers'] ) ) : ?>
		<?php foreach ( $r['pages'][0]['headers'] as $h => $v ) : ?>
			<tr><td><code><?php echo esc_html( $h ); ?></code></td><td class="<?php echo $v ? 'ok' : 'muted'; ?>"><?php echo esc_html( $v ? $v : __( 'no presente', 'dsmk-legal' ) ); ?></td></tr>
		<?php endforeach; ?>
	<?php endif; ?>
	<tr><td><?php esc_html_e( 'Plugins activos', 'dsmk-legal' ); ?></td><td><?php echo esc_html( implode( ' · ', $env['plugins'] ) ); ?></td></tr>
</table>
<?php endif; ?>

<h2><?php esc_html_e( '10. Histórico de auditorías', 'dsmk-legal' ); ?></h2>
<table class="small"><thead><tr><th><?php esc_html_e( 'Fecha', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Origen', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Puntuación', 'dsmk-legal' ); ?></th><th>✔ / ! / ✘</th><th><?php esc_html_e( 'Páginas', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Rastreadores sin consentimiento', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Servicios nuevos', 'dsmk-legal' ); ?></th><th><?php esc_html_e( 'Ref.', 'dsmk-legal' ); ?></th></tr></thead><tbody>
	<?php foreach ( array_slice( DSMKC_Audit::history(), 0, 20 ) as $h ) : ?>
		<tr<?php echo $h['id'] === $r['id'] ? ' style="font-weight:700"' : ''; ?>><td><?php echo esc_html( wp_date( 'd/m/Y H:i', $h['date'] ) ); ?></td><td><?php echo esc_html( 'automatico' === $h['trigger'] ? __( 'Automática', 'dsmk-legal' ) : __( 'Manual', 'dsmk-legal' ) ); ?></td><td><?php echo (int) $h['score']; ?></td><td><?php echo esc_html( $h['summary']['ok'] . ' / ' . $h['summary']['warn'] . ' / ' . $h['summary']['fail'] ); ?></td><td><?php echo (int) $h['totals']['pages']; ?></td><td class="<?php echo $h['totals']['leaks'] ? 'fail' : 'ok'; ?>"><?php echo (int) $h['totals']['leaks']; ?></td><td><?php echo esc_html( $h['new_services'] ? implode( ', ', $h['new_services'] ) : '—' ); ?></td><td><code><?php echo esc_html( $h['id'] ); ?></code></td></tr>
	<?php endforeach; ?>
</tbody></table>

<h2><?php esc_html_e( '11. Declaración técnica de cumplimiento', 'dsmk-legal' ); ?></h2>
<div class="box"><?php echo esc_html( self::declaration_text( $r ) ); ?></div>
<p class="small muted"><?php echo esc_html( sprintf( __( 'Informe generado automáticamente por DSMK Legal %1$s el %2$s. Las comprobaciones técnicas reflejan el estado de la web en la fecha de la auditoría. Este informe no sustituye el asesoramiento jurídico.', 'dsmk-legal' ), DSMKC_VERSION, wp_date( 'd/m/Y H:i' ) ) ); ?></p>
</body>
</html>
		<?php
		exit;
	}
}
