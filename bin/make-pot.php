<?php
/**
 * Genera languages/dsmk-legal.pot sin WP-CLI ni gettext.
 *
 * @package   DSMK_Legal
 * @file      bin/make-pot.php
 * @since     2.2.0
 * @author    DSMK Agencia Digital <hola@dsmk.es>
 *
 * Responsabilidad: extraer las cadenas traducibles del dominio `dsmk-legal`
 * (con sus comentarios «translators:», contexto y plurales) recorriendo los
 * tokens de PHP, y escribir la plantilla .pot.
 *
 * Uso: php bin/make-pot.php   (desde la raíz del plugin)
 * Si está disponible WP-CLI, la alternativa oficial es:
 *   wp i18n make-pot . languages/dsmk-legal.pot --exclude=tests,bin
 *
 * Herramienta de desarrollo: no se incluye en el ZIP de producción.
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$root = dirname( __DIR__ );
$map  = array( // función => posiciones de argumentos: [singular, plural|null, contexto|null, dominio].
	'__'         => array( 0, null, null, 1 ),
	'_e'         => array( 0, null, null, 1 ),
	'esc_html__' => array( 0, null, null, 1 ),
	'esc_html_e' => array( 0, null, null, 1 ),
	'esc_attr__' => array( 0, null, null, 1 ),
	'esc_attr_e' => array( 0, null, null, 1 ),
	'_x'         => array( 0, null, 1, 2 ),
	'esc_html_x' => array( 0, null, 1, 2 ),
	'esc_attr_x' => array( 0, null, 1, 2 ),
	'_n'         => array( 0, 1, null, 3 ),
	'_nx'        => array( 0, 1, 3, 4 ),
	'_n_noop'    => array( 0, 1, null, 2 ),
);

$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
$items = array();

foreach ( $files as $file ) {
	$path = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
	if ( '.php' !== substr( $path, -4 ) || preg_match( '#^(tests|bin|vendor|node_modules)/#', $path ) ) {
		continue;
	}
	$tokens     = token_get_all( file_get_contents( $file->getPathname() ) );
	$translator = '';
	$count      = count( $tokens );
	for ( $i = 0; $i < $count; $i++ ) {
		$t = $tokens[ $i ];
		if ( is_array( $t ) && T_COMMENT === $t[0] && false !== stripos( $t[1], 'translators:' ) ) {
			$translator = trim( preg_replace( '#^(/\*+|//)|\*+/$#', '', $t[1] ) );
			continue;
		}
		if ( ! is_array( $t ) || T_STRING !== $t[0] || ! isset( $map[ $t[1] ] ) ) {
			continue;
		}
		// Debe seguir «(» y no ser una declaración ni un método.
		$prev = $i - 1;
		while ( $prev >= 0 && is_array( $tokens[ $prev ] ) && T_WHITESPACE === $tokens[ $prev ][0] ) {
			$prev--;
		}
		if ( $prev >= 0 && is_array( $tokens[ $prev ] ) && in_array( $tokens[ $prev ][0], array( T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON ), true ) ) {
			continue;
		}
		$j = $i + 1;
		while ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			$j++;
		}
		if ( '(' !== $tokens[ $j ] ) {
			continue;
		}
		// Recoger argumentos de primer nivel: solo literales de cadena simples.
		$args  = array();
		$cur   = null;
		$depth = 0;
		for ( $k = $j + 1; $k < $count; $k++ ) {
			$x = $tokens[ $k ];
			if ( '(' === $x || '[' === $x ) {
				$depth++;
				$cur = false;
			} elseif ( ')' === $x || ']' === $x ) {
				if ( 0 === $depth ) {
					$args[] = $cur;
					break;
				}
				$depth--;
			} elseif ( ',' === $x && 0 === $depth ) {
				$args[] = $cur;
				$cur    = null;
			} elseif ( is_array( $x ) && T_WHITESPACE === $x[0] ) {
				continue;
			} elseif ( 0 === $depth && is_array( $x ) && T_CONSTANT_ENCAPSED_STRING === $x[0] && null === $cur ) {
				$cur = stripcslashes( substr( $x[1], 1, -1 ) );
				if ( "'" === $x[1][0] ) {
					$cur = str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), substr( $x[1], 1, -1 ) );
				}
			} else {
				$cur = false; // Expresión no literal.
			}
		}
		list( $si, $pi, $ci, $di ) = $map[ $t[1] ];
		if ( ! isset( $args[ $di ] ) || 'dsmk-legal' !== $args[ $di ] || ! is_string( $args[ $si ] ?? null ) ) {
			$translator = '';
			continue;
		}
		$ctx = null !== $ci && is_string( $args[ $ci ] ?? null ) ? $args[ $ci ] : '';
		$key = $ctx . "\4" . $args[ $si ];
		if ( ! isset( $items[ $key ] ) ) {
			$items[ $key ] = array(
				'msgid'  => $args[ $si ],
				'plural' => null !== $pi && is_string( $args[ $pi ] ?? null ) ? $args[ $pi ] : null,
				'ctx'    => $ctx,
				'refs'   => array(),
				'notes'  => array(),
			);
		}
		$items[ $key ]['refs'][] = $path . ':' . $t[2];
		if ( '' !== $translator ) {
			$items[ $key ]['notes'][ $translator ] = true;
		}
		$translator = '';
	}
}

/**
 * Escapa una cadena para el formato PO.
 *
 * @since  2.2.0
 * @param  string $s Cadena.
 * @return string
 */
function dsmkc_po( $s ) {
	return '"' . str_replace( array( '\\', '"', "\n", "\t" ), array( '\\\\', '\\"', '\\n', '\\t' ), $s ) . '"';
}

preg_match( '/Version:\s*([\d.]+)/', file_get_contents( $root . '/dsmk-legal.php' ), $v );
$out  = "# Copyright (C) " . gmdate( 'Y' ) . " DSMK Agencia Digital\n# This file is distributed under the GPL-2.0-or-later.\nmsgid \"\"\nmsgstr \"\"\n";
$out .= "\"Project-Id-Version: DSMK Legal {$v[1]}\\n\"\n\"Report-Msgid-Bugs-To: hola@dsmk.es\\n\"\n\"POT-Creation-Date: " . gmdate( 'Y-m-d H:i+0000' ) . "\\n\"\n\"MIME-Version: 1.0\\n\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Content-Transfer-Encoding: 8bit\\n\"\n\"Language: es_ES\\n\"\n\"X-Domain: dsmk-legal\\n\"\n\n";
foreach ( $items as $it ) {
	foreach ( array_keys( $it['notes'] ) as $n ) {
		$out .= '#. ' . $n . "\n";
	}
	$out .= '#: ' . implode( ' ', array_slice( $it['refs'], 0, 6 ) ) . "\n";
	if ( false !== strpos( $it['msgid'], '%' ) ) {
		$out .= "#, php-format\n";
	}
	if ( '' !== $it['ctx'] ) {
		$out .= 'msgctxt ' . dsmkc_po( $it['ctx'] ) . "\n";
	}
	$out .= 'msgid ' . dsmkc_po( $it['msgid'] ) . "\n";
	if ( $it['plural'] ) {
		$out .= 'msgid_plural ' . dsmkc_po( $it['plural'] ) . "\nmsgstr[0] \"\"\nmsgstr[1] \"\"\n\n";
	} else {
		$out .= "msgstr \"\"\n\n";
	}
}
if ( ! is_dir( $root . '/languages' ) ) {
	mkdir( $root . '/languages' );
}
file_put_contents( $root . '/languages/dsmk-legal.pot', $out );
fwrite( STDOUT, count( $items ) . " cadenas → languages/dsmk-legal.pot\n" );
