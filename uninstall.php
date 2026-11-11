<?php
/**
 * DSMK Legal — Desinstalación.
 *
 * @package   DSMK_Legal
 * @file      uninstall.php
 * @since     1.0.0
 * @author    DSMK Agencia Digital <hola@dsmk.es>
 *
 * Solo borra datos si el administrador activó «Borrar todos los datos al
 * desinstalar» (Avanzado). Por defecto se conservan los registros de
 * consentimiento, que son prueba legal. Las páginas legales nunca se borran.
 *
 * 2.2.0: también borra transients (dsmkc_*), las copias de contenido guardadas
 * al sustituir páginas (_dsmkc_previous_*) y recorre todas las webs de un
 * multisite (cada una con su propio ajuste «Borrar datos»).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Borra los datos del plugin en la web actual si su ajuste lo permite.
 *
 * @since  2.2.0
 * @return void
 */
function dsmkc_uninstall_site() {
	global $wpdb;
	$settings = get_option( 'dsmkc_settings', array() );
	if ( empty( $settings['borrar_datos'] ) ) {
		return;
	}
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabla propia y limpieza de desinstalación.
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'dsmkc_consents' );
	foreach ( array( 'dsmkc_settings', 'dsmkc_data', 'dsmkc_db_version', 'dsmkc_audits', 'dsmkc_plugin_version', 'dsmkc_settings_updated' ) as $option ) {
		delete_option( $option );
	}
	// Transients: limitación de frecuencia (dsmkc_rl_*), token de escaneo, avisos.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_dsmkc_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_dsmkc_' ) . '%'
		)
	);
	// Copias de seguridad del contenido de páginas sustituidas.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( '_dsmkc_previous_' ) . '%'
		)
	);
	// phpcs:enable
	delete_site_transient( 'dsmkc_update_info' ); // Caché de actualizaciones (2.3.0).
	wp_clear_scheduled_hook( 'dsmkc_daily' );
	wp_clear_scheduled_hook( 'dsmkc_weekly_scan' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $dsmkc_site_id ) {
		switch_to_blog( $dsmkc_site_id );
		dsmkc_uninstall_site();
		restore_current_blog();
	}
} else {
	dsmkc_uninstall_site();
}
