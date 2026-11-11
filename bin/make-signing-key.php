<?php
/**
 * Genera el par de claves de firma de las actualizaciones (Ed25519).
 *
 * @package   DSMK_Legal
 * @file      bin/make-signing-key.php
 * @since     2.3.0
 * @author    DSMK Agencia Digital <hola@dsmk.es>
 *
 * Responsabilidad: crear en el equipo de DSMK la clave con la que se firman
 * las versiones. Escribe la clave PÚBLICA en
 * includes/class-dsmkc-updater.php (DSMKC_Updater::PUBLIC_KEY) y muestra la
 * PRIVADA una sola vez por pantalla, para guardarla en el gestor de
 * contraseñas y en el secreto UPDATE_SIGNING_KEY del repositorio.
 *
 * Uso (desde la raíz del plugin, una sola vez antes de la primera publicación):
 *   php bin/make-signing-key.php
 *   git commit -am "Clave pública de firma de actualizaciones"
 *
 * Seguridad: la clave privada nunca se escribe en disco ni en el repositorio.
 * Ejecutarlo de nuevo crea otra clave: las webs que ya tengan una versión con
 * la clave anterior solo aceptarán versiones firmadas con esa (ver README ›
 * Actualizaciones › Rotar la clave). Pide confirmación si ya hay una clave.
 *
 * Herramienta de desarrollo: no se incluye en el ZIP de producción.
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
	fwrite( STDERR, "Falta la extensión sodium de PHP.\n" );
	exit( 1 );
}

$file = dirname( __DIR__ ) . '/includes/class-dsmkc-updater.php';
$src  = (string) file_get_contents( $file );
if ( ! preg_match( "/const PUBLIC_KEY = '([^']*)';/", $src, $m ) ) {
	fwrite( STDERR, "No se encuentra DSMKC_Updater::PUBLIC_KEY en {$file}\n" );
	exit( 1 );
}
if ( '' !== $m[1] && ! in_array( '--force', $argv, true ) ) {
	fwrite( STDOUT, "Ya hay una clave pública. Si ya se publicó alguna versión con ella, cambiarla deja\n" );
	fwrite( STDOUT, "sin actualizaciones a esas webs (ver README › Rotar la clave). ¿Generar otra? [s/N] " );
	if ( 's' !== strtolower( trim( (string) fgets( STDIN ) ) ) ) {
		exit( 0 );
	}
}

$pair    = sodium_crypto_sign_keypair();
$public  = base64_encode( sodium_crypto_sign_publickey( $pair ) );
$private = base64_encode( sodium_crypto_sign_secretkey( $pair ) );
sodium_memzero( $pair );

file_put_contents( $file, preg_replace( "/const PUBLIC_KEY = '[^']*';/", "const PUBLIC_KEY = '{$public}';", $src, 1 ) );

fwrite( STDOUT, "\nClave pública escrita en includes/class-dsmkc-updater.php:\n  {$public}\n\n" );
fwrite( STDOUT, "CLAVE PRIVADA (se muestra solo ahora; no la pegues en chats ni tickets):\n\n{$private}\n\n" );
fwrite( STDOUT, "1. Guárdala en el gestor de contraseñas de DSMK.\n" );
fwrite( STDOUT, "2. GitHub › Settings › Secrets and variables › Actions › New repository secret › UPDATE_SIGNING_KEY.\n" );
fwrite( STDOUT, "3. Haz commit del cambio de la clave pública y publica la versión.\n" );
