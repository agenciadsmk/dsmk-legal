<?php
/**
 * Plugin Name:       DSMK Legal
 * Plugin URI:        https://dsmk.es
 * Description:       Hub legal para webs de clientes: aviso legal, política de privacidad, política de cookies con gestión del consentimiento (LSSI, RGPD, Guía AEPD), términos y condiciones de servicios, condiciones de venta y desistimiento. Auditoría técnica automatizada con histórico e informe PDF, importación/exportación JSON y plantilla para rellenarlo con IA.
 * Version:           2.4.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Tested up to:      6.8
 * Update URI:        https://agenciadsmk.github.io/dsmk-legal/
 * Author:            DSMK Agencia Digital
 * Author URI:        https://dsmk.es
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dsmk-legal
 * Domain Path:       /languages
 *
 * @package   DSMK_Legal
 * @file      dsmk-legal.php
 * @since     1.0.0
 * @author    DSMK Agencia Digital <hola@dsmk.es>
 *
 * Responsabilidad: definir constantes, cargar clases y registrar activación.
 * No contiene lógica de negocio (ver includes/class-dsmkc-plugin.php).
 *
 * Compatibilidad: sustituye a «DSMK Cookies» (agenciadsmk/dsmk-cookies), que
 * comparte prefijo, opciones y tabla. Al activarlo desactiva DSMK Cookies para
 * evitar dos banners; importa el titular y conserva el registro de
 * consentimientos (ver CLAUDE.md › Convivencia).
 *
 * «Update URI» (2.3.0): las actualizaciones llegan del servidor de DSMK
 * (DSMKC_Updater) y WordPress nunca lo sustituirá por un plugin de
 * wordpress.org con el mismo nombre de carpeta.
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'DSMKC_VERSION' ) ) {
	// DSMK Cookies 1.x está activo y se ha cargado antes (orden alfabético): no se
	// redeclaran clases. Se desactiva el plugin antiguo; en la siguiente carga ya
	// funciona DSMK Legal con los mismos datos.
	add_action(
		'admin_init',
		static function () {
			if ( current_user_can( 'activate_plugins' ) && function_exists( 'deactivate_plugins' ) ) {
				deactivate_plugins( 'dsmk-cookies/dsmk-cookies.php' );
			}
		}
	);
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-info"><p><strong>DSMK Legal</strong> sustituye a DSMK Cookies: importa los datos del titular y conserva el registro de consentimientos. DSMK Cookies se ha desactivado: recarga la página y revisa los ajustes.</p></div>';
		}
	);
	return;
}

define( 'DSMKC_VERSION', '2.4.1' );
// '2' desde 2.2.1: el plugin «DSMK Cookies» 1.0.0 de Arturo también guarda
// dsmkc_db_version = '1' con otra tabla; con '1' nuestra tabla no se completaba.
define( 'DSMKC_DB_VERSION', '2' );
define( 'DSMKC_FILE', __FILE__ );
define( 'DSMKC_DIR', plugin_dir_path( __FILE__ ) );
define( 'DSMKC_URL', plugin_dir_url( __FILE__ ) );

require_once DSMKC_DIR . 'includes/class-dsmkc-settings.php';
require_once DSMKC_DIR . 'includes/class-dsmkc-services.php';
require_once DSMKC_DIR . 'includes/class-dsmkc-blocker.php';
require_once DSMKC_DIR . 'includes/class-dsmkc-legal.php';
require_once DSMKC_DIR . 'includes/class-dsmkc-documents.php';
require_once DSMKC_DIR . 'includes/class-dsmkc-frontend.php';
require_once DSMKC_DIR . 'includes/class-dsmkc-consent-log.php';
require_once DSMKC_DIR . 'includes/class-dsmkc-scanner.php';
require_once DSMKC_DIR . 'includes/class-dsmkc-report.php';
require_once DSMKC_DIR . 'includes/class-dsmkc-audit.php';
require_once DSMKC_DIR . 'includes/class-dsmkc-tools.php';
require_once DSMKC_DIR . 'includes/class-dsmkc-updater.php';
require_once DSMKC_DIR . 'includes/class-dsmkc-plugin.php';
require_once DSMKC_DIR . 'includes/functions.php';
require_once DSMKC_DIR . 'admin/class-dsmkc-admin.php';

register_activation_hook( __FILE__, array( 'DSMKC_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'DSMKC_Plugin', 'deactivate' ) );

// Arranque en `init` (prioridad 0), no en `plugins_loaded`: desde WordPress 6.7
// las traducciones deben cargarse en `init` o después, y el esquema de ajustes
// usa textos traducibles. Todos los hooks que usa el plugin (template_redirect,
// wp_head, rest_api_init, admin_menu…) se disparan después de `init`.
add_action( 'init', array( 'DSMKC_Plugin', 'instance' ), 0 );
