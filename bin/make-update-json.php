<?php
/**
 * Genera el info.json de actualizaciones para una versión ya empaquetada.
 *
 * @package   DSMK_Legal
 * @file      bin/make-update-json.php
 * @since     2.3.0
 * @author    DSMK Agencia Digital <hola@dsmk.es>
 *
 * Responsabilidad: leer la cabecera del plugin y el CHANGELOG.md, calcular la
 * huella SHA-256 del ZIP y escribir el JSON que consulta DSMKC_Updater en
 * cada web. Lo usa .github/workflows/release.yml; también sirve a mano.
 *
 * Uso (desde la raíz del plugin):
 *   DSMK_SIGNING_KEY=… php bin/make-update-json.php --zip=dist/dsmk-legal-2.3.0.zip \
 *       --base-url=https://agenciadsmk.github.io/dsmk-legal [--out=dist/info.json]
 *
 * Comprueba también que la clave que firma es la pareja de la clave pública
 * incluida en el plugin (DSMKC_Updater::PUBLIC_KEY).
 *
 * Firma: la variable de entorno DSMK_SIGNING_KEY (clave secreta Ed25519 en
 * base64, secreto UPDATE_SIGNING_KEY de GitHub) firma
 * «dsmk-legal\n<versión>\n<sha256>». Sin ella no se genera nada: las webs
 * rechazan versiones sin firma (ver DSMKC_Updater).
 *
 * Falla (código 1) si el ZIP no existe, si su nombre no lleva la versión de la
 * cabecera, si el CHANGELOG no tiene entrada para esa versión o si falta la
 * clave de firma: así no se publica nunca una versión sin documentar ni firmar.
 *
 * Herramienta de desarrollo: no se incluye en el ZIP de producción.
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$root = dirname( __DIR__ );
$opts = getopt( '', array( 'zip:', 'base-url:', 'out::', 'expected-pubkey::' ) );

/**
 * Termina con un mensaje de error.
 *
 * @since  2.3.0
 * @param  string $msg Mensaje.
 * @return void
 */
function dsmkc_fail( $msg ) {
	fwrite( STDERR, "make-update-json: {$msg}\n" );
	exit( 1 );
}

if ( empty( $opts['zip'] ) || empty( $opts['base-url'] ) ) {
	dsmkc_fail( 'uso: --zip=dist/dsmk-legal-X.Y.Z.zip --base-url=https://… [--out=dist/info.json]' );
}
$zip  = $opts['zip'];
$base = rtrim( $opts['base-url'], '/' );
$out  = ! empty( $opts['out'] ) ? $opts['out'] : dirname( $zip ) . '/info.json';
if ( ! is_file( $zip ) ) {
	dsmkc_fail( "no existe {$zip}" );
}
if ( 0 !== strpos( $base, 'https://' ) ) {
	dsmkc_fail( '--base-url debe ser https (el plugin rechaza http)' );
}

// Cabecera del plugin.
$main    = file_get_contents( $root . '/dsmk-legal.php' );
$headers = array();
foreach ( array( 'Version', 'Requires at least', 'Requires PHP', 'Tested up to' ) as $h ) {
	$headers[ $h ] = preg_match( '/^[ \t\/*#@]*' . preg_quote( $h, '/' ) . ':\s*(.+)$/mi', $main, $m ) ? trim( $m[1] ) : '';
}
$version = $headers['Version'];
if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
	dsmkc_fail( 'la cabecera no tiene una versión X.Y.Z' );
}
if ( basename( $zip ) !== 'dsmk-legal-' . $version . '.zip' ) {
	dsmkc_fail( 'el ZIP no se llama dsmk-legal-' . $version . '.zip' );
}

/**
 * Convierte el Markdown sencillo del CHANGELOG a HTML (títulos, listas, negrita, código).
 *
 * @since  2.3.0
 * @param  string $md Markdown.
 * @return string HTML.
 */
function dsmkc_md_to_html( $md ) {
	$html  = '';
	$stack = array(); // Niveles de lista abiertos (sangría).
	$close = static function ( $to ) use ( &$stack, &$html ) {
		while ( count( $stack ) > $to ) {
			array_pop( $stack );
			$html .= "</li></ul>\n";
		}
	};
	$inline = static function ( $t ) {
		$t = htmlspecialchars( $t, ENT_QUOTES, 'UTF-8' );
		$t = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $t );
		$t = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $t );
		return preg_replace( '/\[([^\]]+)\]\((https:[^)]+)\)/', '<a href="$2">$1</a>', $t );
	};
	foreach ( preg_split( '/\R/', $md ) as $line ) {
		if ( preg_match( '/^(\s*)[-*] (.*)$/', $line, $m ) ) {
			$level = intdiv( strlen( str_replace( "\t", '  ', $m[1] ) ), 2 ) + 1;
			if ( $level > count( $stack ) ) {
				while ( $level > count( $stack ) ) {
					$stack[] = true;
					$html   .= '<ul><li>';
				}
			} else {
				$close( $level );
				$html .= "</li>\n<li>";
			}
			$html .= $inline( $m[2] );
		} elseif ( preg_match( '/^#{2,4}\s+(.*)$/', $line, $m ) ) {
			$close( 0 );
			$html .= '<h4>' . $inline( $m[1] ) . "</h4>\n";
		} elseif ( '' === trim( $line ) ) {
			$close( 0 );
		} elseif ( $stack ) {
			$html .= ' ' . $inline( trim( $line ) ); // Continuación de un punto de la lista.
		} else {
			$html .= '<p>' . $inline( trim( $line ) ) . "</p>\n";
		}
	}
	$close( 0 );
	return $html;
}

// Entradas del CHANGELOG: la de esta versión (obligatoria) y las dos anteriores.
$changelog = (string) file_get_contents( $root . '/CHANGELOG.md' );
$parts     = preg_split( '/^## \[/m', $changelog );
$entries   = array();
foreach ( array_slice( $parts, 1 ) as $part ) {
	if ( preg_match( '/^([\d.]+)\]([^\n]*)\n(.*)$/s', $part, $m ) ) {
		$entries[ $m[1] ] = array( trim( $m[2], " —-\t" ), trim( $m[3] ) );
	}
}
if ( ! isset( $entries[ $version ] ) ) {
	dsmkc_fail( "CHANGELOG.md no tiene la entrada «## [{$version}]»" );
}
$html = '';
foreach ( array_slice( $entries, 0, 3, true ) as $v => $entry ) {
	$html .= '<h3>' . htmlspecialchars( $v . ( '' !== $entry[0] ? ' — ' . $entry[0] : '' ), ENT_QUOTES, 'UTF-8' ) . "</h3>\n" . dsmkc_md_to_html( $entry[1] );
}

// Firma Ed25519 (mismo mensaje que DSMKC_Updater::signed_message()).
$secret = base64_decode( (string) getenv( 'DSMK_SIGNING_KEY' ), true );
if ( ! is_string( $secret ) || SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen( $secret ) ) {
	dsmkc_fail( 'falta DSMK_SIGNING_KEY (clave secreta Ed25519 en base64; secreto UPDATE_SIGNING_KEY del repositorio)' );
}
// La clave que firma debe ser la pareja de DSMKC_Updater::PUBLIC_KEY del ZIP:
// si no, las webs rechazarían la versión (o no hay clave pública todavía).
$derived = base64_encode( sodium_crypto_sign_publickey_from_secretkey( $secret ) );
$updater = (string) file_get_contents( $root . '/includes/class-dsmkc-updater.php' );
$embed   = preg_match( "/const PUBLIC_KEY = '([^']*)';/", $updater, $pk ) ? $pk[1] : '';
if ( ! empty( $opts['expected-pubkey'] ) ) {
	$embed = $opts['expected-pubkey']; // Pruebas y rotación de clave (README › Rotar la clave). CI no lo usa.
}
if ( '' === $embed ) {
	dsmkc_fail( 'DSMKC_Updater::PUBLIC_KEY está vacía: ejecuta «php bin/make-signing-key.php», haz commit y vuelve a etiquetar' );
}
if ( ! hash_equals( $embed, $derived ) ) {
	dsmkc_fail( 'UPDATE_SIGNING_KEY no corresponde a DSMKC_Updater::PUBLIC_KEY: las webs rechazarían esta versión' );
}
$sha256    = hash_file( 'sha256', $zip );
$signature = base64_encode( sodium_crypto_sign_detached( "dsmk-legal\n{$version}\n{$sha256}", $secret ) );
sodium_memzero( $secret );

$info = array(
	'slug'           => 'dsmk-legal',
	'name'           => 'DSMK Legal',
	'version'        => $version,
	'download_url'   => $base . '/dsmk-legal-' . $version . '.zip',
	'sha256'         => $sha256,
	'signature'      => $signature,
	'requires'       => $headers['Requires at least'],
	'requires_php'   => $headers['Requires PHP'],
	'tested'         => $headers['Tested up to'],
	'last_updated'   => gmdate( 'Y-m-d H:i:s' ),
	'changelog_html' => $html,
);
file_put_contents( $out, json_encode( $info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
fwrite( STDOUT, "{$out}: {$version} · sha256 {$sha256} · firmado\n" );
