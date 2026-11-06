<?php
/**
 * Genera el informe técnico de una auditoría simulada (proceso aparte porque
 * render_printable() termina con exit). Uso: php tests/php/render-report.php > informe.html
 *
 * @package DSMK_Legal
 * @file    tests/php/render-report.php
 * @since   2.1.0
 */
require __DIR__ . '/bootstrap.php';
foreach ( array( 'consent-log', 'scanner', 'report', 'audit', 'plugin', 'tools' ) as $c ) {
	require DSMKC_DIR . "includes/class-dsmkc-$c.php";
}
define( 'ARRAY_A', 'ARRAY_A' );
class FakeWpdb { public $prefix = 'wp_'; function prepare( $q ) { return $q; } function get_results() { return array( array( 'action' => 'accept', 'n' => 12, 'ym' => '2026-10' ), array( 'action' => 'reject', 'n' => 5, 'ym' => '2026-10' ) ); } }
$GLOBALS['wpdb'] = new FakeWpdb();
require __DIR__ . '/fixture-site.php';
dsmkc_test_setup( array( 'doc_terminos' => 1 ) );
$record = DSMKC_Audit::run( 'manual' );
DSMKC_Report::render_printable( $record );
