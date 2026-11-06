<?php
/**
 * DSMK Legal — Herramientas: exportar, importar, briefing para IA y autodetección.
 *
 * @package   DSMK_Legal
 * @file      includes/class-dsmkc-tools.php
 * @since     2.0.0
 * @author    DSMK Agencia Digital <hola@dsmk.es>
 * @license   GPL-2.0-or-later
 *
 * Responsabilidad:
 *   - export_payload(): JSON con la configuración completa (sin IDs de página).
 *   - import_payload(): admite el JSON exportado (de DSMK Legal o DSMK Cookies 1.x),
 *     un objeto con «settings» o un objeto plano de ajustes (lo que suele
 *     devolver una IA). Solo se aplican claves conocidas y todo se sanea con
 *     el esquema. Devuelve qué se aplicó y qué se ignoró.
 *   - ai_brief(): documento Markdown «con un clic» para pegar en una IA
 *     (Claude) junto con la información del cliente: describe cada campo,
 *     valores permitidos, valores actuales y el formato JSON de respuesta,
 *     listo para importar con «Pegar JSON».
 *   - detect(): sugiere valores para campos vacíos a partir de WooCommerce,
 *     los ajustes de WordPress y los textos legales que ya tenga la web.
 *
 * Seguridad:
 *   - Nada se ejecuta: el JSON solo se decodifica y se sanea campo a campo.
 *   - Los IDs de página nunca se importan (son de la web de origen) y la
 *     creación automática de páginas tampoco (decisión de cada web, 2.0.1).
 *   - detect() solo lee contenido de la propia web; no hace peticiones externas.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Herramientas de datos.
 *
 * @since 2.0.0
 */
final class DSMKC_Tools {

	/** @var string[] Claves que nunca se importan. */
	// 2.3.0: el origen y la activación de las actualizaciones tampoco (un JSON
	// importado no puede decidir qué código se instala en la web).
	const NO_IMPORT = array( 'pagina_aviso', 'pagina_privacidad', 'pagina_cookies', 'pagina_terminos', 'pagina_condiciones', 'pagina_desistimiento', 'paginas_auto', 'update_url', 'update_auto', 'update_key' );

	/**
	 * Configuración exportable.
	 *
	 * @since  2.0.0
	 * @return array
	 */
	public static function export_payload() {
		$settings = DSMKC_Settings::all();
		foreach ( self::NO_IMPORT as $key ) {
			unset( $settings[ $key ] );
		}
		foreach ( DSMKC_Settings::fields() as $key => $field ) {
			if ( ! empty( $field['secret'] ) ) {
				unset( $settings[ $key ] ); // Claves (2.3.0): nunca salen de la web.
			}
		}
		$data = DSMKC_Settings::data();
		return array(
			'plugin'    => 'dsmk-legal',
			'version'   => DSMKC_VERSION,
			'site'      => home_url( '/' ),
			'generated' => gmdate( 'c' ),
			'settings'  => $settings,
			'manual'    => $data['manual'],
			'overrides' => $data['overrides'],
		);
	}

	/**
	 * Extrae un JSON aunque venga envuelto en texto o en bloque ```json``` (respuestas de IA).
	 *
	 * @since  2.0.0
	 * @param  string $raw Texto.
	 * @return array|null
	 */
	public static function parse_json( $raw ) {
		$raw = trim( (string) $raw );
		if ( preg_match( '/```(?:json)?\s*(\{.*\})\s*```/s', $raw, $m ) ) {
			$raw = $m[1];
		} elseif ( false !== strpos( $raw, '{' ) ) {
			$raw = substr( $raw, strpos( $raw, '{' ), strrpos( $raw, '}' ) - strpos( $raw, '{' ) + 1 );
		}
		$json = json_decode( $raw, true );
		return is_array( $json ) ? $json : null;
	}

	/**
	 * Importa una configuración.
	 *
	 * @since  2.0.0
	 * @param  array $json         Datos decodificados.
	 * @param  bool  $only_empty   true = solo rellena campos vacíos (no pisa lo existente).
	 * @return array{applied:string[], ignored:string[], manual:int}
	 */
	public static function import_payload( array $json, $only_empty = false ) {
		$fields   = DSMKC_Settings::fields();
		$settings = isset( $json['settings'] ) && is_array( $json['settings'] ) ? $json['settings'] : $json;
		$current  = DSMKC_Settings::all();
		$apply    = array();
		$ignored  = array();

		foreach ( $settings as $key => $value ) {
			if ( ! isset( $fields[ $key ] ) || in_array( $key, self::NO_IMPORT, true ) || ! empty( $fields[ $key ]['secret'] ) ) {
				if ( ! in_array( $key, array( 'plugin', 'version', 'site', 'generated', 'manual', 'overrides', 'settings' ), true ) ) {
					$ignored[] = (string) $key;
				}
				continue;
			}
			if ( $only_empty && ! self::is_empty_value( $current[ $key ], $fields[ $key ] ) ) {
				continue;
			}
			if ( is_bool( $value ) ) {
				$value = $value ? 1 : 0;
			}
			$apply[ $key ] = $value;
		}
		if ( $apply ) {
			DSMKC_Settings::save( $apply, array_keys( $apply ) );
		}

		$manual_count = 0;
		$changes      = array();
		if ( isset( $json['manual'] ) && is_array( $json['manual'] ) ) {
			$manual = array();
			foreach ( $json['manual'] as $row ) {
				if ( is_array( $row ) && ! empty( $row['name'] ) && in_array( $row['category'] ?? '', DSMKC_Services::CATEGORIES, true ) ) {
					$manual[] = array(
						'name'     => substr( sanitize_text_field( $row['name'] ), 0, 120 ),
						'category' => $row['category'],
						'provider' => sanitize_text_field( $row['provider'] ?? '' ),
						'purpose'  => sanitize_text_field( $row['purpose'] ?? '' ),
						'duration' => sanitize_text_field( $row['duration'] ?? '' ),
						'party'    => 'tercero' === ( $row['party'] ?? '' ) ? 'tercero' : 'propia',
					);
				}
			}
			$changes['manual'] = $manual;
			$manual_count      = count( $manual );
		}
		if ( isset( $json['overrides'] ) && is_array( $json['overrides'] ) ) {
			$overrides = array();
			foreach ( $json['overrides'] as $id => $cat ) {
				if ( in_array( $cat, array_merge( DSMKC_Services::CATEGORIES, array( 'excluded' ) ), true ) ) {
					$overrides[ sanitize_key( $id ) ] = $cat;
				}
			}
			$changes['overrides'] = $overrides;
		}
		if ( $changes ) {
			DSMKC_Settings::update_data( $changes );
			DSMKC_Plugin::on_settings_saved();
		}

		return array( 'applied' => array_keys( $apply ), 'ignored' => $ignored, 'manual' => $manual_count );
	}

	/**
	 * ¿Está vacío un valor según su tipo?
	 *
	 * @since  2.0.0
	 * @param  mixed $value Valor.
	 * @param  array $field Definición.
	 * @return bool
	 */
	private static function is_empty_value( $value, array $field ) {
		if ( 'multicheck' === $field['type'] ) {
			return empty( $value );
		}
		if ( in_array( $field['type'], array( 'bool', 'int', 'select', 'color' ), true ) ) {
			return $value === $field['default'];
		}
		return '' === trim( (string) $value );
	}

	/* =====================================================================
	 * Briefing para IA
	 * ===================================================================== */

	/**
	 * Documento Markdown para que una IA rellene la configuración.
	 *
	 * @since  2.0.0
	 * @return string Markdown.
	 */
	public static function ai_brief() {
		$schema   = DSMKC_Settings::schema();
		$values   = DSMKC_Settings::all();
		$skip     = array( 'actualizaciones', 'estilo', 'categorias', 'servicios', 'registro', 'avanzado' );
		$woo      = class_exists( 'WooCommerce' );
		$services = array();
		foreach ( DSMKC_Services::active() as $service ) {
			$services[] = $service['name'] . ' (' . $service['category'] . ')';
		}
		$pages = array();
		foreach ( get_pages( array( 'post_status' => 'publish', 'number' => 40 ) ) as $page ) {
			$pages[] = '- ' . $page->post_title . ': ' . get_permalink( $page );
		}

		$md  = "# Briefing para rellenar DSMK Legal\n\n";
		$md .= "Eres un asistente legal-técnico. Tu tarea es rellenar la configuración del plugin «DSMK Legal» (hub legal de WordPress) de la web indicada abajo, para que genere automáticamente el aviso legal, la política de privacidad, la política de cookies y, si procede, los términos y condiciones de servicios, las condiciones de venta y el desistimiento, conforme a la normativa española (LSSI, RGPD/LOPDGDD, Guía de cookies de la AEPD, TRLGDCU).\n\n";
		$md .= "## Instrucciones\n\n";
		$md .= "1. Revisa la web (y sus textos legales actuales, si los tiene) y la información adicional que te facilite el usuario.\n";
		$md .= "2. Rellena SOLO los campos de los que tengas datos fiables. No inventes NIF, domicilios, datos registrales ni emails: si no los encuentras, déjalos fuera y dilo al final.\n";
		$md .= "3. Activa los documentos que correspondan: `doc_condiciones` y `doc_desistimiento` si la web vende online a consumidores; `doc_terminos` si vende servicios.\n";
		$md .= "4. En `extra_*` añade solo cláusulas específicas que no cubra el texto estándar (HTML sencillo: <p>, <ul>, <strong>).\n";
		$md .= "5. Responde con UN ÚNICO bloque ```json``` con este formato y, después, una lista breve de datos que faltan o que el cliente debe confirmar:\n\n";
		$md .= "```json\n{\n  \"plugin\": \"dsmk-legal\",\n  \"settings\": {\n    \"titular_nombre\": \"…\",\n    \"doc_condiciones\": 1\n  }\n}\n```\n\n";
		$md .= "## Web\n\n";
		$md .= '- URL: ' . home_url( '/' ) . "\n";
		$md .= '- Nombre: ' . DSMKC_Legal::site_name() . "\n";
		$md .= '- WooCommerce (tienda online): ' . ( $woo ? 'sí' : 'no' ) . "\n";
		$md .= '- Servicios con cookies detectados: ' . ( $services ? implode( ', ', $services ) : 'ninguno todavía' ) . "\n";
		if ( $pages ) {
			$md .= "- Páginas publicadas:\n" . implode( "\n", $pages ) . "\n";
		}
		$md .= "\n## Campos\n\nFormato: `clave` (tipo) — descripción. Valores permitidos. **Valor actual**.\n";

		foreach ( $schema as $section_key => $section ) {
			if ( in_array( $section_key, $skip, true ) ) {
				continue;
			}
			$md .= "\n### " . $section['title'] . "\n\n";
			if ( ! empty( $section['intro'] ) ) {
				$md .= '_' . $section['intro'] . "_\n\n";
			}
			foreach ( $section['fields'] as $key => $field ) {
				if ( 'page' === $field['type'] || 'css' === $field['type'] || ! empty( $field['secret'] ) ) {
					continue;
				}
				$line = '- `' . $key . '` (' . self::type_label( $field['type'] ) . ') — ' . $field['label'];
				if ( ! empty( $field['help'] ) ) {
					$line .= '. ' . $field['help'];
				}
				if ( ! empty( $field['options'] ) ) {
					$opts = array();
					foreach ( $field['options'] as $opt => $label ) {
						$opts[] = '`' . $opt . '` = ' . $label;
					}
					$line .= ' Valores: ' . implode( '; ', $opts ) . '.';
				}
				$current = $values[ $key ];
				$line   .= ' **Actual:** ' . ( is_array( $current ) ? wp_json_encode( $current ) : ( '' === (string) $current ? '(vacío)' : wp_strip_all_tags( (string) $current ) ) );
				$md     .= $line . "\n";
			}
		}
		$md .= "\n## Información adicional del cliente\n\n(Pega aquí lo que sepas: CIF, razón social, datos registrales, dirección, servicios, forma de pago, envíos, devoluciones…)\n";
		return $md;
	}

	/**
	 * Etiqueta legible de un tipo de campo.
	 *
	 * @since  2.0.0
	 * @param  string $type Tipo.
	 * @return string
	 */
	private static function type_label( $type ) {
		$map = array( 'bool' => '0/1', 'int' => 'número', 'multicheck' => 'lista', 'html' => 'HTML', 'textarea' => 'texto largo', 'select' => 'opción', 'email' => 'email', 'color' => 'color hex' );
		return isset( $map[ $type ] ) ? $map[ $type ] : 'texto';
	}

	/* =====================================================================
	 * Autodetección
	 * ===================================================================== */

	/**
	 * Sugerencias de valores para los campos vacíos.
	 *
	 * @since  2.0.0
	 * @return array<string, array{value:mixed, source:string}>
	 */
	public static function detect() {
		$s   = DSMKC_Settings::all();
		$out = array();
		$set = static function ( $key, $value, $source ) use ( &$out, $s ) {
			$fields = DSMKC_Settings::fields();
			if ( ! isset( $fields[ $key ] ) || isset( $out[ $key ] ) || '' === $value || array() === $value || null === $value ) {
				return;
			}
			$current = $s[ $key ];
			// Vacío = sin valor o con el valor por defecto (nunca se pisa algo configurado).
			$empty   = is_array( $current ) ? ( empty( $current ) || $current === $fields[ $key ]['default'] ) : ( '' === trim( (string) $current ) || $current === $fields[ $key ]['default'] );
			if ( $empty && $current !== $value ) {
				$out[ $key ] = array( 'value' => $value, 'source' => $source );
			}
		};

		// 1. WooCommerce.
		if ( class_exists( 'WooCommerce' ) ) {
			$set( 'doc_condiciones', 1, 'WooCommerce activo' );
			$set( 'doc_desistimiento', 1, 'WooCommerce activo' );
			$address = trim( implode( ', ', array_filter( array( get_option( 'woocommerce_store_address' ), get_option( 'woocommerce_store_address_2' ), trim( get_option( 'woocommerce_store_postcode' ) . ' ' . get_option( 'woocommerce_store_city' ) ) ) ) ) );
			$set( 'titular_domicilio', $address, 'WooCommerce › Ajustes › Dirección de la tienda' );
			$set( 'titular_email', sanitize_email( (string) get_option( 'woocommerce_email_from_address' ) ), 'WooCommerce › Emails' );
			$finalidades = array_unique( array_merge( (array) $s['finalidades'], array( 'tienda' ), 'yes' === get_option( 'woocommerce_enable_myaccount_registration' ) ? array( 'registro' ) : array() ) );
			$set( 'finalidades', array_values( $finalidades ), 'WooCommerce activo' );
			$set( 'tienda_pagos', self::detect_gateways(), 'Pasarelas de pago activas en WooCommerce' );
			$set( 'tienda_impuestos', 'yes' === get_option( 'woocommerce_prices_include_tax' ) ? 'iva_incluido' : '', 'WooCommerce › Impuestos' );
		}

		// 2. Textos legales que ya tenga la web.
		$text = self::existing_legal_text();
		if ( '' !== $text ) {
			if ( preg_match( '/\b([ABCDEFGHJNPQRSUVW]-?\d{7}-?[0-9A-J]|\d{8}-?[A-HJ-NP-TV-Z]|[XYZ]-?\d{7}-?[A-Z])\b/', $text, $m ) ) {
				$set( 'titular_nif', strtoupper( str_replace( '-', '', $m[1] ) ), 'Textos legales actuales de la web' );
			}
			if ( preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $m ) ) {
				$set( 'titular_email', strtolower( $m[0] ), 'Textos legales actuales de la web' );
			}
			if ( preg_match( '/(\+34[\s.]?)?[6789]\d{2}[\s.]?\d{3}[\s.]?\d{3}\b/', $text, $m ) ) {
				$set( 'titular_telefono', trim( $m[0] ), 'Textos legales actuales de la web' );
			}
			if ( preg_match( '/Registro Mercantil de [^.\n]{3,160}/iu', $text, $m ) ) {
				$set( 'titular_registro', trim( $m[0] ), 'Textos legales actuales de la web' );
			}
			if ( preg_match( '/(?:titular|razón social|denominación social)\s*[:\-]?\s*([^\n,.;]{3,80}(?:S\.?L\.?U?|S\.?A\.?|S\.?C\.?|SLU|SL|SA|SCOOP)?)/iu', $text, $m ) ) {
				$set( 'titular_nombre', trim( $m[1] ), 'Textos legales actuales de la web' );
			}
			if ( preg_match( '/(?:domicilio|dirección)\s*(?:social)?\s*[:\-]?\s*([^\n]{8,140}?\b\d{5}\b[^\n.;]{0,60})/iu', $text, $m ) ) {
				$set( 'titular_domicilio', trim( $m[1], " ,.;:" ), 'Textos legales actuales de la web' );
			}
			if ( preg_match( '/\b(S\.?L\.?U?|S\.?A\.?U?|SLU|SL|SA)\b/u', (string) ( $out['titular_nombre']['value'] ?? $s['titular_nombre'] ) ) ) {
				$set( 'titular_tipo', 'sociedad', 'Forma jurídica en el nombre' );
			}
		}

		// 3. WordPress.
		$set( 'titular_email', sanitize_email( (string) get_option( 'admin_email' ) ), 'Email del administrador de WordPress (revisar)' );

		return $out;
	}

	/**
	 * Pasarelas de pago activas en WooCommerce → formas de pago.
	 *
	 * @since  2.0.0
	 * @return string[]
	 */
	private static function detect_gateways() {
		$map = array(
			'stripe'                  => 'tarjeta',
			'redsys'                  => 'tarjeta',
			'woocommerce_payments'    => 'tarjeta',
			'ppcp-gateway'            => 'paypal',
			'paypal'                  => 'paypal',
			'bizum'                   => 'bizum',
			'bacs'                    => 'transferencia',
			'cod'                     => 'contrareembolso',
		);
		$out = array();
		foreach ( $map as $gateway => $key ) {
			$settings = get_option( 'woocommerce_' . $gateway . '_settings' );
			if ( is_array( $settings ) && isset( $settings['enabled'] ) && 'yes' === $settings['enabled'] ) {
				$out[] = $key;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Texto plano de las páginas legales existentes que NO son del plugin.
	 *
	 * @since  2.0.0
	 * @return string
	 */
	private static function existing_legal_text() {
		$text  = '';
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 30,
				'no_found_rows'  => true,
			)
		);
		foreach ( $pages as $page ) {
			$haystack = strtolower( $page->post_title . ' ' . $page->post_name );
			if ( ! preg_match( '/aviso|legal|privacidad|privacy|condiciones|contacto|cookies/', $haystack ) ) {
				continue;
			}
			if ( false !== strpos( $page->post_content, '[dsmk_' ) ) {
				continue; // Página ya generada por el plugin.
			}
			// Elementor guarda el contenido real en _elementor_data; usamos ambos.
			$raw   = $page->post_content . ' ' . (string) get_post_meta( $page->ID, '_elementor_data', true );
			$raw   = str_replace( array( '\n', '\/', '\"' ), array( "\n", '/', '"' ), $raw );
			// Saltos de línea entre bloques: sin ellos los párrafos se pegan («SLUNIF:»).
			$raw   = preg_replace( '#</(p|div|li|h[1-6]|td|th|tr|section)>|<br\s*/?>#i', "\n", $raw );
			$text .= "\n" . html_entity_decode( wp_strip_all_tags( $raw, false ), ENT_QUOTES, 'UTF-8' );
		}
		return $text;
	}

	/**
	 * Aplica las sugerencias seleccionadas.
	 *
	 * @since  2.0.0
	 * @param  string[] $keys Claves aceptadas.
	 * @return int Número de campos aplicados.
	 */
	public static function apply_detected( array $keys ) {
		$detected = self::detect();
		$apply    = array();
		foreach ( $keys as $key ) {
			if ( isset( $detected[ $key ] ) ) {
				$apply[ $key ] = $detected[ $key ]['value'];
			}
		}
		if ( $apply ) {
			DSMKC_Settings::save( $apply, array_keys( $apply ) );
		}
		return count( $apply );
	}
}
