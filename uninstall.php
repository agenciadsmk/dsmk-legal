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
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$dsmkc_settings = get_option( 'dsmkc_settings', array() );
if ( empty( $dsmkc_settings['borrar_datos'] ) ) {
	return;
}

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Borrado de tabla propia.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'dsmkc_consents' );
delete_option( 'dsmkc_settings' );
delete_option( 'dsmkc_data' );
delete_option( 'dsmkc_db_version' );
delete_option( 'dsmkc_audits' );
delete_option( 'dsmkc_plugin_version' );
delete_option( 'dsmkc_settings_updated' );
wp_clear_scheduled_hook( 'dsmkc_daily' );
wp_clear_scheduled_hook( 'dsmkc_weekly_scan' );
