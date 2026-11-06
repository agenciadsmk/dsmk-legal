<?php
/**
 * DSMK Legal — API pública para otros plugins y temas de DSMK.
 *
 * @package   DSMK_Legal
 * @file      includes/functions.php
 * @since     2.0.0
 * @author    DSMK Agencia Digital <hola@dsmk.es>
 * @license   GPL-2.0-or-later
 *
 * Pensada para que el plugin de desistimiento de WooCommerce (y futuros
 * módulos) reutilicen los datos del titular y enlacen los documentos sin
 * duplicar configuración. Comprobar siempre con function_exists().
 *
 * Integración típica (plugin de desistimiento):
 *
 *   // 1. Mostrar su botón/formulario dentro de la página de desistimiento del hub:
 *   add_filter( 'dsmkc_withdrawal_form', function ( $html ) {
 *       return my_withdrawal_form_html();
 *   } );
 *
 *   // 2. O usar su propia página: en el hub, Tienda › Desistimiento › «Página de otro
 *   //    plugin» y asignarla en Documentos › Página: desistimiento.
 *
 *   // 3. Datos del titular para sus emails:
 *   $email = dsmk_legal_get( 'desist_email' ) ?: dsmk_legal_get( 'titular_email' );
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'dsmk_legal_get' ) ) {
	/**
	 * Valor de un ajuste del hub legal.
	 *
	 * @since  2.0.0
	 * @param  string $key      Clave (ver DSMKC_Settings::schema()).
	 * @param  mixed  $fallback Valor por defecto.
	 * @return mixed
	 */
	function dsmk_legal_get( $key, $fallback = null ) {
		return DSMKC_Settings::get( $key, $fallback );
	}
}

if ( ! function_exists( 'dsmk_legal_page_url' ) ) {
	/**
	 * URL de un documento legal publicado.
	 *
	 * @since  2.0.0
	 * @param  string $doc aviso | privacidad | cookies | terminos | condiciones | desistimiento.
	 * @return string URL o ''.
	 */
	function dsmk_legal_page_url( $doc ) {
		return DSMKC_Legal::page_url( $doc );
	}
}

if ( ! function_exists( 'dsmk_legal_has_consent' ) ) {
	/**
	 * ¿Ha aceptado el visitante una categoría de cookies? (lado servidor).
	 *
	 * Útil para no imprimir código de seguimiento propio sin consentimiento.
	 * En páginas cacheadas usar la API JS: window.DSMKCookies.allowed('analytics').
	 *
	 * @since  2.0.0
	 * @param  string $category preferences | analytics | marketing.
	 * @return bool
	 */
	function dsmk_legal_has_consent( $category ) {
		if ( empty( $_COOKIE['dsmkc_consent'] ) ) {
			return false;
		}
		$consent = json_decode( wp_unslash( $_COOKIE['dsmkc_consent'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Solo se lee como JSON y se compara.
		if ( ! is_array( $consent ) || (int) ( $consent['v'] ?? 0 ) !== (int) DSMKC_Settings::data()['policy_version'] ) {
			return false;
		}
		return ! empty( $consent['c'][ $category ] );
	}
}
