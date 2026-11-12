# CLAUDE.md — DSMK Legal

Contexto y reglas para trabajar este plugin con IA (o a mano) sin romper el cumplimiento legal ni la calidad. **Léelo entero antes de tocar código.**

## Qué es

Hub legal propio de DSMK para webs WordPress de clientes, sin coste por web. Sustituye a «DSMK Cookies» (ver Convivencia). Genera y mantiene:

| Documento | Shortcode | Base legal principal |
|---|---|---|
| Aviso legal | `[dsmk_aviso_legal]` | LSSI art. 10 |
| Política de privacidad | `[dsmk_politica_privacidad]` | RGPD art. 13, LOPDGDD |
| Política de cookies + banner y bloqueo | `[dsmk_politica_cookies]` | LSSI art. 22.2, Guía AEPD 2023 |
| Términos y condiciones del servicio | `[dsmk_terminos_servicio]` | Ley 7/1998, TRLGDCU, TRLPI, Ley 3/2004 |
| Condiciones generales de venta | `[dsmk_condiciones_venta]` | LSSI arts. 23-28, TRLGDCU arts. 60, 66 bis, 102-108, 114-127 |
| Derecho de desistimiento | `[dsmk_desistimiento]` + `[dsmk_desistimiento_formulario]` | TRLGDCU anexos A y B, Directiva (UE) 2023/2673 |

Flujo de uso: **formulario → botones de páginas**. Desde 2.0.1 nada se crea solo: en la pestaña Documentos cada documento activo tiene «Usar esta página» (con la página parecida de la web preseleccionada y la opción de sustituir su contenido por el shortcode) o «Crear página nueva». «Crear las páginas que faltan» no crea las que ya tienen una página parecida. La creación automática al guardar (`paginas_auto`) existe pero está desactivada por defecto y no se puede importar. Las páginas nunca se sobrescriben sin marcar «Sustituir»; si se sustituye, se guarda copia (`_dsmkc_previous_content`, `_dsmkc_previous_elementor_edit_mode`).

Rellenado rápido: **Herramientas** → autodetección (WooCommerce + textos legales existentes) → briefing para IA (.md) → pegar el JSON que devuelve la IA.

## Arquitectura

| Archivo | Responsabilidad |
|---|---|
| `dsmk-legal.php` | Constantes, carga, activación. Desactiva DSMK Cookies 1.x si está activo. |
| `includes/class-dsmkc-plugin.php` | Arranque, cron, sincronización de servicios, vaciado de cachés. |
| `includes/class-dsmkc-settings.php` | **Esquema único** de ajustes (defaults + saneado + formulario + briefing IA) y estado interno. |
| `includes/class-dsmkc-documents.php` | **Registro de documentos** (filtro `dsmkc_legal_documents`), creación de páginas, términos, condiciones, desistimiento, cláusulas adicionales. |
| `includes/class-dsmkc-legal.php` | Aviso legal, privacidad, política de cookies, textos del banner, enlaces del pie. |
| `includes/class-dsmkc-updater.php` | Actualizaciones desde el servidor de DSMK: aviso, detalles, firma Ed25519 + SHA-256, carpeta, automáticas. |
| `includes/class-dsmkc-tools.php` | Exportar, importar (archivo, pegado, JSON de IA, JSON de 1.x), briefing IA, autodetección. |
| `includes/functions.php` | API pública: `dsmk_legal_get()`, `dsmk_legal_page_url()`, `dsmk_legal_has_consent()`. |
| `uninstall.php` | Borrado opcional (ajuste «Borrar datos»), también en multisite. |
| `includes/data/services.php` | Catálogo de 42 servicios con cookies (detección, bloqueo, documentación). |
| `includes/class-dsmkc-services.php` | Servicios activos, categorías, reglas de bloqueo, tabla de cookies. |
| `includes/class-dsmkc-blocker.php` | Bloqueo previo en el HTML (output buffer). |
| `assets/js/dsmkc-head.js` | En línea al inicio del `<head>`: Consent Mode v2 + interceptor de `createElement`. |
| `assets/js/dsmkc-consent.js` | Banner, panel, decisiones, activación, revocación, registro. |
| `includes/class-dsmkc-frontend.php` | Cabecera, integraciones, banner, variables CSS (Elementor). |
| `includes/class-dsmkc-consent-log.php` | Registro de consentimientos (tabla, REST, CSV, purga). |
| `includes/class-dsmkc-scanner.php` | Escaneo de servidor (semanal) y de navegador. |
| `includes/class-dsmkc-report.php` | Comprobaciones de configuración por área e informe técnico imprimible de una auditoría. |
| `includes/class-dsmkc-audit.php` | Auditoría con un botón / semanal: escaneo por página (visitante y escáner), entorno, SSL, cabeceras, formularios, terceros; histórico (`dsmkc_audits`). |
| `admin/class-dsmkc-admin.php` | Panel por pestañas y todas las acciones (nonce + capacidad). |

El prefijo interno sigue siendo `dsmkc` / `DSMKC_` (opciones `dsmkc_settings`, `dsmkc_data`, tabla `{prefix}dsmkc_consents`) para conservar los datos de 1.x. **No renombrar.**

## Integración con el plugin de desistimiento (Arturo)

El hub aporta los **textos** y el plugin de desistimiento la **función online** (botón/formulario, Directiva 2023/2673). Contrato de integración:

```php
// 1. Inyectar el botón/formulario funcional en la página de desistimiento del hub.
add_filter( 'dsmkc_withdrawal_form', function ( $html ) {
    return mi_formulario_desistimiento(); // HTML ya escapado.
} );

// 2. (Alternativa) Usar la página propia del plugin: en el hub, Tienda › Desistimiento ›
//    «Página de otro plugin» y asignarla en Documentos › Página: desistimiento.
//    O por código:
add_filter( 'dsmkc_legal_documents', function ( $docs ) {
    $docs['desistimiento']['external'] = true;
    return $docs;
} );

// 3. Datos del titular sin duplicar configuración.
if ( function_exists( 'dsmk_legal_get' ) ) {
    $email = dsmk_legal_get( 'desist_email' ) ?: dsmk_legal_get( 'titular_email' );
    $url   = dsmk_legal_page_url( 'condiciones' );
}
```

Cualquier documento nuevo (p. ej. política de envíos) se añade igual con `dsmkc_legal_documents` (claves: `title`, `shortcode`, `setting`, `toggle`, `footer`, `external`).

## Reglas que NO se pueden romper (cumplimiento)

1. **Cookies**: Aceptar y Rechazar en la primera capa con la misma clase `.dsmkc-btn--primary`; nada premarcado; nada no necesario se ejecuta sin consentimiento; revocar = borrar cookies + recargar; validez máxima 730 días; sin muro de cookies.
2. **Apartados obligatorios de la política de cookies** (LSSI / AEPD): mantener los verificados en `tests/php/run-tests.php` [4].
3. **Consumo**: desistimiento mínimo 14 días naturales (validado en el esquema); garantía legal 3 años bienes / 2 años digitales; entrega máxima 30 días; reembolso en 14 días; formulario modelo literal del anexo B.
4. **No enlazar la plataforma ODR** de la UE (derogada en julio de 2025).
5. **Nunca inventar datos** en los textos: si falta un dato obligatorio se muestra `[pendiente: …]` resaltado.
6. **Nada de crear páginas sin acción del usuario** (decisión de producto 2.0.1). Las páginas existentes no se sobrescriben salvo «Sustituir» marcado y siempre con copia. Los IDs de página y `paginas_auto` **nunca se importan**.
7. La importación solo acepta claves del esquema y sanea cada valor (no ejecuta nada).
8. **Universal**: el plugin no menciona programas de ayudas ni organismos concretos (Kit Digital, Red.es, Cámara…). Las comprobaciones citan solo la norma.

## Convivencia con «DSMK Cookies» 1.0.0 (repo `agenciadsmk/dsmk-cookies`, de Arturo)

Es **otro plugin**, no una versión anterior de este, aunque comparte prefijo `dsmkc_`, clases `DSMKC_*`, opción `dsmkc_settings`, tabla `{prefix}dsmkc_consents`, cookie `dsmkc_consent` y la opción `dsmkc_db_version`.
- Nunca pueden estar activos a la vez: `dsmk-legal.php` vuelve sin cargar nada si `DSMKC_VERSION` ya está definida y desactiva `dsmk-cookies/dsmk-cookies.php`.
- `DSMKC_DB_VERSION` debe ser siempre distinta de la suya («1»).
- Migración: `DSMKC_Plugin::migrate_dsmk_cookies_settings()` y `DSMKC_Consent_Log::migrate_dsmk_cookies()`.

## Actualizaciones (2.3.0)

- `DSMKC_Updater` solo engancha filtros; la petición la lanza WordPress cuando comprueba actualizaciones. El JSON por defecto está en `https://agenciadsmk.github.io/dsmk-legal/info.json` y lo publica `.github/workflows/release.yml` al subir la etiqueta `vX.Y.Z`.
- Reglas que no se pueden romper:
  - **Firma Ed25519 obligatoria.** El mensaje firmado es `DSMKC_Updater::signed_message()`. La clave pública está en `DSMKC_Updater::PUBLIC_KEY` (la escribe `bin/make-signing-key.php`) y la privada solo en el secreto `UPDATE_SIGNING_KEY` y en el gestor de contraseñas, nunca en el repositorio ni en un chat.
  - **Huella SHA-256 firmada, verificada antes de instalar, con fallo cerrado** (`upgrader_pre_download`).
  - Solo https.
  - Se mantienen `Update URI` y `external`.
  - Se respeta la carpeta instalada (`upgrader_source_selection`).
  - Se compara con `$transient->checked`, no con `DSMKC_VERSION`.
  - `update_url`, `update_auto` y `update_key` están en `NO_IMPORT`: ni se importan ni se exportan.
  - En multisite se ignoran los ajustes del subsitio (`settings_allowed()`).
- Publicar = versión en la cabecera + `DSMKC_VERSION` + `tests/php/bootstrap.php` + entrada `## [X.Y.Z]` en el CHANGELOG + etiqueta. El flujo falla si la etiqueta y la versión no coinciden, si falta la entrada del CHANGELOG o si falta la clave de firma.

## Decisiones de seguridad (2.2.0) — no revertir

- **Escáner**: token efímero (transient `dsmkc_scan_token`, 15 min) solo en la cabecera `X-DSMKC-Scan` de peticiones del servidor a su propio dominio (`DSMKC_Scanner::request()` no sigue redirecciones a otros hosts). En el navegador: `?dsmkc_scan=<nonce>` + capacidad. Toda respuesta del escáner es no cacheable (`DSMKC_Blocker::scan_nocache()`) y lleva `dsmkc_nc`.
- **Flags de constructores** (`?bricks`, `?et_fb`…): solo con sesión y `edit_posts`.
- **Endpoint público `dsmkc/v1/consent`**: validación estricta (regex con `\z`, esquema REST), 500 si no se guarda, límite por HMAC de la IP completa (`dsmkc_log_rate_limit`), IP real configurable (`dsmkc_client_ip`), IP guardada anonimizada y UA como HMAC.
- **«Aceptar todas»** solo acepta las categorías declaradas en el banner (las que tienen servicios detectados o manuales). Un servicio no detectado sigue bloqueado aunque se acepte todo: el consentimiento tiene que ser informado. Escanear para que aparezca.
- **Consent Mode avanzado**: GTM/GA4/Ads sin bloquear (`DSMKC_Services::consent_mode_free()`); el resto de etiquetas, bloqueadas.
- `DSMKC_Settings::sanitize_field()` recibe valores **ya sin barras**: no volver a añadir `wp_unslash()` dentro.
- No usar `wp_cache_flush()`.

## Reglas de código (protocolo DSMK)

- **Cabecera de archivo** obligatoria: `@package DSMK_Legal`, `@file`, `@since`, `@author`, responsabilidad y notas de seguridad.
- **Docblock en cada función** con `@since`, `@param`, `@return`; decisiones no obvias comentadas en español.
- Prefijo `dsmkc` / `DSMKC_` / `.dsmkc-`; API pública `dsmk_legal_*`.
- Seguridad: capacidad `manage_options` (filtro `dsmkc_capability`) + nonce en toda acción; sanear al entrar (esquema), escapar al salir; `$wpdb->prepare()`; sin `eval`; patrones de bloqueo = subcadenas literales.
- JavaScript de front-end en ES5 sin dependencias.
- PHP 7.4+, WordPress 6.0+.
- Cada cambio: entrada en `CHANGELOG.md` + pruebas en `TESTS.md`.
- Nuevo campo de configuración = añadirlo SOLO al esquema (`DSMKC_Settings::schema()`): formulario, saneado, exportación y briefing IA salen solos.

## Pruebas (obligatorias antes de publicar)

```bash
php tests/php/run-tests.php                         # 233 pruebas de lógica
php tests/php/render-report.php > informe.html      # informe técnico de una auditoría simulada
php -S 127.0.0.1:8765 tests/e2e/router.php &        # web de prueba con rastreadores locales
node tests/e2e/consent.e2e.js [carpeta-capturas]    # 46 pruebas en Chromium real
```

Antes de cada versión, en una web real de staging: activar, rellenar Titular, comprobar que se crean las páginas, «Informe» en verde, ventana de incógnito sin cookies no técnicas.

## Herramientas de desarrollo

```bash
composer install        # WPCS 3 + PHPCompatibilityWP (solo desarrollo)
composer lint           # PHP_CodeSniffer con phpcs.xml.dist
composer test           # = php tests/php/run-tests.php
composer pot            # = php bin/make-pot.php → languages/dsmk-legal.pot
composer build          # = bash bin/build.sh → dist/dsmk-legal-<versión>.zip
```

CI en `.github/workflows/ci.yml`: sintaxis en PHP 7.4 / 8.0 / 8.3 y pruebas de lógica; WPCS en modo informativo. Publicación en `.github/workflows/release.yml` (ver README › Actualizaciones).

## Empaquetado

`bash bin/build.sh`: pasa las pruebas, regenera el .pot y crea el ZIP excluyendo lo listado en `.distignore` (`tests/`, `bin/`, `.github/`, `CLAUDE.md`, `TESTS.md`, `ENTREGA.md`, configuración de desarrollo).
