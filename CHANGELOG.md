# Changelog — DSMK Legal

Formato: versión semántica (MAYOR.MENOR.PARCHE). Cada entrada indica qué cambia, por qué y qué pruebas se pasaron (detalle en `TESTS.md`).

## [2.3.0] — 2026-11-07

### Añadido
- **Actualizaciones desde el servidor de DSMK** (`includes/class-dsmkc-updater.php`). Cada web muestra el aviso normal de WordPress «Hay una nueva versión disponible», con la ventana «Ver detalles» y su changelog, y se puede actualizar con un clic. Por defecto se instala sola en segundo plano.
  - **Origen:** `info.json` en GitHub Pages (`https://agenciadsmk.github.io/dsmk-legal/info.json`). Se puede cambiar con la constante `DSMK_LEGAL_UPDATE_URL`, el ajuste «URL de actualizaciones» (solo en webs que no son multisite) o el filtro `dsmkc_update_url`.
  - **Firma digital Ed25519:** cada versión se firma con la clave privada de DSMK (secreto `UPDATE_SIGNING_KEY` de GitHub) y las webs la verifican con la clave pública incluida en el plugin. Aunque alguien manipulara GitHub Pages o el servidor de descargas, ninguna web instalaría un ZIP sin firmar.
  - **Clave de firma generada en el equipo de DSMK:** `bin/make-signing-key.php` escribe la pública en el plugin y muestra la privada una sola vez. Mientras `PUBLIC_KEY` esté vacía, el plugin no acepta actualizaciones y la publicación se niega a firmar. `make-update-json` también se niega si la clave que firma no es la pareja de la del plugin.
  - **Huella SHA-256 firmada:** el ZIP se descarga y se comprueba antes de instalarse. Si falta la huella o no coincide, la instalación se cancela: nunca se instala nada sin verificar.
  - **Cabecera `Update URI`** y detalles sin enlace a wordpress.org (`external`).
  - **Se respeta la carpeta en la que está instalado** (p. ej. `dsmk-legal-main/`), para que el plugin no se desactive al actualizar (`upgrader_source_selection`).
  - **Caché:** 12 horas, o 3 si el servidor falla. «Comprobar de nuevo», en Escritorio › Actualizaciones, fuerza también nuestra consulta.
  - **Clave por web opcional** (ajuste o constante `DSMK_LEGAL_UPDATE_KEY`). Va en cabeceras y solo al mismo host que el JSON, sin seguir redirecciones.
  - **Ni la URL, ni la clave, ni la activación automática se exportan o se importan:** un JSON importado no puede redirigir las actualizaciones. Los campos `secret` del esquema se muestran como contraseña.
  - **Multisite:** los ajustes de actualización de un subsitio se ignoran; manda la red (constantes en `wp-config.php` e interruptor de Red › Plugins).
- **Herramientas › Actualizaciones:** versión instalada y publicada, última comprobación, servidor, último error y botón «Comprobar ahora».
- **Publicación automática** (`.github/workflows/release.yml`). Al subir la etiqueta `vX.Y.Z`:
  - Trabajo **build**: comprueba que la etiqueta coincide con la versión, pasa las pruebas, genera el ZIP y el `info.json` firmado (`bin/make-update-json.php`) y crea o completa la Release de GitHub. Se puede relanzar sin errores.
  - Trabajo **pages**: publica en GitHub Pages y conserva los ZIP de las 5 versiones anteriores.
  - Trabajo **ssh** (opcional): copia los archivos a un servidor propio si están los secretos `UPDATE_SSH_*`.

### Cambiado
- La cabecera del plugin añade `Tested up to` y `Update URI`.
- Se corrige el comentario de compatibilidad: DSMK Cookies es otro plugin, no una versión anterior.
- La desinstalación borra también la caché de actualizaciones.
- En las pruebas, los datos reales de clientes se sustituyen por datos ficticios, porque el repositorio es público.

### Revisión independiente
- Encontró 11 problemas antes de publicar; todos están corregidos y tienen prueba:
  - el administrador de un subsitio podía redirigir las actualizaciones de toda la red;
  - importar un JSON podía redirigirlas;
  - tras una actualización automática se reinstalaba la misma versión;
  - la verificación SHA no fallaba cerrada;
  - el plugin se desactivaba si estaba en una carpeta con otro nombre;
  - la primera publicación iba a fallar por la protección del entorno de Pages;
  - y otros menores.

### Pruebas
- PHP: 216/216. Nueva sección [17] con 49 pruebas: firma, huella, caché, versión en disco, fallo cerrado, carpeta, multisite, clave, importación y exportación, automáticas, «Comprobar de nuevo», generador firmado.
- E2E: 46/46.

## [2.2.1] — 2026-11-06

### Corregido
- **Webs que tenían instalado «DSMK Cookies» 1.0.0 (el plugin de Arturo, repositorio `agenciadsmk/dsmk-cookies`): no se guardaban las pruebas de consentimiento.**
  - **Causa raíz:** los dos plugins guardan la versión de su tabla en `dsmkc_db_version` con el valor «1», pero sus tablas `{prefix}dsmkc_consents` tienen columnas distintas. Al encontrar ese «1», DSMK Legal no creaba sus columnas (`categories`, `services`, `policy_version`…) y todos los registros fallaban.
  - **Corrección:** `DSMKC_DB_VERSION` pasa a «2». `dbDelta()` añade las columnas que faltan sin borrar las del otro plugin, y las filas antiguas reciben `categories` y `policy_version` a partir de sus columnas `preferences`/`statistics`/`marketing`/`revision`. La acción original se conserva tal cual.
- **Ajustes de DSMK Cookies 1.0.0:** comparten la opción `dsmkc_settings` con otra estructura (claves anidadas `company`, `banner`…). Ahora se importan titular, NIF, domicilio y email a los campos vacíos de DSMK Legal y se quitan las claves anidadas para que no aparezcan en la exportación JSON.
- La columna `action` se amplía a `varchar(20)`, porque las acciones de DSMK Cookies, como `accept_all`, no cabían en 12 caracteres.
- El aviso al sustituir DSMK Cookies ya no dice que «conserva toda su configuración». Ahora explica que importa el titular y conserva el registro.

### Notas
- La cookie de consentimiento de DSMK Cookies (`dsmkc_consent`, sin `v`) no es válida para DSMK Legal, así que el visitante vuelve a ver el banner una vez. Es lo correcto, porque cambian la política y el texto.

### Pruebas
- PHP: 167/167. Nueva sección [16] con 7 pruebas de convivencia y migración.
- E2E: 46/46.

## [2.2.0] — 2026-11-04

Revisión completa de seguridad y calidad antes de pasar el plugin al protocolo de desarrollo de DSMK. Auditoría de seguridad hecha por un revisor independiente (solo lectura) y verificada contra el código.

### Seguridad
- **Las respuestas del escáner ya no se pueden guardar en caché.** El escáner pide la web sin bloqueos ni banner; una caché de página o de servidor podía guardar ese HTML y servírselo a los visitantes. Ahora esas respuestas se marcan como no cacheables (`DONOTCACHEPAGE`, `nocache_headers()`, `Cache-Control: private, no-store` y LiteSpeed), y las peticiones del escáner llevan un parámetro único `dsmkc_nc`.
- **El token de escaneo ahora es temporal.** Antes era permanente, viajaba en la URL del escaneo en navegador (y los rastreadores la veían) y se reenviaba a otros dominios si una página redirigía.
  - Servidor: el token dura 15 minutos y se crea al empezar un escaneo o una auditoría.
  - Navegador: se usa un nonce más la sesión del administrador.
  - Redirecciones: se siguen a mano y solo dentro del propio dominio (`DSMKC_Scanner::request()`).
  - La migración borra el token antiguo.
- **Los parámetros de los constructores visuales** (`?bricks=1`, `?et_fb=1`, `?elementor-preview`…) solo desactivan el bloqueo para usuarios con sesión que pueden editar. Antes, un enlace así cargaba todos los rastreadores sin consentimiento.
- **Registro de consentimientos:**
  - El id con salto de línea final se rechaza (`\z`).
  - Los servicios se recortan para que quepan en la columna, y los valores que no son texto se ignoran (esquema REST con `items`/`maxItems`).
  - Si la base de datos no guarda la fila, el endpoint responde 500 (antes respondía 201).
  - El límite de frecuencia va por un hash de la IP completa (que no se guarda): 60 cada 10 minutos, ajustable con el filtro `dsmkc_log_rate_limit`. Antes era por /24 y detrás de un proxy se perdían pruebas reales.
  - Nuevo filtro `dsmkc_client_ip` para leer la IP real detrás de un CDN.
- **Avisos del panel:** el texto de detalle ya no llega por la URL (`dsmkc_info`), sino en un transient del usuario.

### Corregido
- **Bloqueo del contenido insertado después de cargar la página** (innerHTML, insertAdjacentHTML, jQuery `.html()`, «cargar más», filtros AJAX):
  - Se filtran las cadenas HTML antes de que el navegador las analice, porque las `<img>` empiezan a descargarse durante el análisis.
  - El MutationObserver sigue activo tras `load` y revisa también `<iframe>`, `<img>` y los descendientes.
  - Los píxeles `<img>` solo se comparan con los patrones de imagen.
- **Consent Mode avanzado:** GTM, GA4 y Google Ads se cargan sin bloquear y respetan Consent Mode por sí mismos. Antes, el interceptor seguía bloqueando `gtm.js`. Las etiquetas que no son de Google inyectadas desde GTM siguen bloqueadas (`DSMKC_Services::consent_mode_free()`).
- **Prueba de consentimiento no guardada:** el navegador la guarda en `localStorage` (máximo 5) y la reintenta en la siguiente página.
- **Barras invertidas:** el formulario las quitaba dos veces y se perdían las legítimas (por ejemplo, CSS `content:"\201C"`). Un array en un campo de texto o número ya no provoca avisos de PHP.
- **Desinstalación** (con «Borrar datos» activado): ahora también borra los transients `dsmkc_*` y las copias `_dsmkc_previous_*`, y recorre todas las webs de un multisite.

### Rendimiento
- `purge_caches()` ya no llama a `wp_cache_flush()`, que vaciaba toda la caché de objetos (Redis compartido, multisite).
- La lista de elementos sin clasificar tiene un tope de 200 (los más recientes), porque `dsmkc_data` se lee en cada visita.

### Calidad y protocolo
- Docblocks completos (con `@since`) en los 17 manejadores del panel.
- `languages/dsmk-legal.pot` (852 cadenas) y `bin/make-pot.php` para regenerarlo sin WP-CLI.
- `index.php` de protección en cada carpeta.
- Repositorio preparado:
  - `phpcs.xml.dist` (WPCS 3 + PHPCompatibilityWP, PHP 7.4+)
  - `composer.json` (lint/test/pot/build)
  - `.editorconfig`, `.gitignore`, `.distignore`
  - `bin/build.sh` (ZIP de producción)
  - CI en GitHub Actions (sintaxis en PHP 7.4/8.0/8.3 y pruebas)
- `CLAUDE.md`: reglas numeradas, herramientas de desarrollo y decisiones de seguridad. `README.md` sin versión fija.

### Pruebas
- PHP: 160/160. Nueva sección [15] con 23 pruebas de regresión, una o más por hallazgo.
- E2E: 46/46. Nuevas [10] (contenido dinámico: iframe, píxel por insertAdjacentHTML y patrón jQuery, activación al aceptar) y [11] (fallo 500 del registro y reintento).

## [2.1.1] — 2026-11-04

### Corregido
- Aviso «Function _load_textdomain_just_in_time was called incorrectly… dsmk-legal» (WordPress 6.7+). El plugin arrancaba en `plugins_loaded` y leía textos traducibles antes de `init`. Ahora arranca en `init` (prioridad 0) y carga ahí sus traducciones.

### Pruebas
- Comprobación estática: ningún `__()` / `_e()` / `esc_html__()` se ejecuta al cargar los archivos (solo dentro de funciones llamadas desde `init` o después). PHP 137/137.

## [2.1.0] — 2026-11-04

Plugin universal y auditoría técnica automatizada con histórico.

### Cambiado
- Eliminadas todas las referencias a programas o organismos concretos (Kit Digital, Red.es, Cámara de Comercio). Las comprobaciones citan solo la norma (LSSI, RGPD, LOPDGDD, Guía AEPD, TRLGDCU).
- El «texto de subsanación» pasa a ser una **declaración técnica de cumplimiento** genérica, con la referencia de la auditoría.
- Pestaña «Informe» → **«Auditoría»**. El escaneo semanal automático es ahora una auditoría completa que queda en el histórico.

### Añadido
- **Auditoría con un botón** (`DSMKC_Audit::run()`): escaneo en navegador + auditoría de servidor. Cada página se pide como visitante sin consentimiento (rastreadores ejecutables, cookies del servidor, banner, enlaces legales, formularios y su información de privacidad, recursos de terceros antes de consentir, Google Fonts) y como escáner (servicios presentes). Incluye entorno (WordPress, PHP, servidor, tema, plugins, caché, idioma), certificado SSL (emisor, caducidad) y cabeceras de seguridad.
- **Histórico de auditorías** (50 últimas; detalle completo en las 10 más recientes) con puntuación, resultados y acceso al informe de cada una.
- **Informe técnico** imprimible a PDF: puntuación 0-100 y KPIs, acciones recomendadas por prioridad, comprobaciones por área (información legal, cookies, privacidad y terceros, prueba del consentimiento, tienda, seguridad), escaneo por página, servicios y transferencias, dominios de terceros, inventario de cookies, banner y su configuración, versiones del banner, registro de consentimientos por mes con tasa de aceptación, documentos legales, entorno técnico, histórico y declaración de cumplimiento.
- `DSMKC_Consent_Log::monthly()`, `DSMKC_Scanner::merge_results()` público, `DSMKC_Report::groups()`.

### Pruebas
- PHP: 137/137 (18 nuevas, con web simulada que incluye una fuga real). Navegador: 40/40.

## [2.0.1] — 2026-11-04

Las páginas legales ya no se crean solas: se crean o asignan con botones, para no duplicar las que ya tiene la web.

### Cambiado
- `paginas_auto` desactivado por defecto. Al actualizar desde 2.0.0 se desactiva también en las webs donde ya estaba instalado (migración única en `DSMKC_Plugin::upgrade()`).
- El importador nunca activa la creación automática (`paginas_auto` en `NO_IMPORT`).
- «Crear las páginas que faltan» pide confirmación con la lista y no crea los documentos para los que la web ya tiene una página parecida.

### Añadido
- Pestaña Documentos con una fila por documento: «Usar esta página» (desplegable con la página parecida preseleccionada y opción de sustituir su contenido por el shortcode) y «Crear página nueva» (con aviso si ya hay una parecida).
- Detección de páginas legales existentes por título y slug (`DSMKC_Documents::suggest_existing()`).
- Sustitución segura: copia del contenido anterior en `_dsmkc_previous_content` y, en páginas de Elementor, desactivación del constructor solo en esa página con copia del modo anterior.
- Aviso (panel e informe) cuando una página asignada no usa el shortcode y muestra su propio texto. Compatible con páginas de Elementor.

### Pruebas
- PHP: 119/119 (14 nuevas). Navegador: 40/40. Panel: 13 pestañas sin errores.

## [2.0.0] — 2026-11-04

Pasa de plugin de cookies a **hub legal** completo. Nuevo nombre: «DSMK Legal» (carpeta `dsmk-legal`). Conserva todos los datos de DSMK Cookies 1.x y lo desactiva al instalarse.

### Añadido
- **Registro de documentos** ampliable por otros plugins (`dsmkc_legal_documents`).
- **Términos y condiciones del servicio** (`[dsmk_terminos_servicio]`): B2B o con consumidores, contratación por presupuesto u online, pago, plazos, revisiones, propiedad intelectual (cesión o licencia), portfolio, confidencialidad y encargo del tratamiento, responsabilidad, cancelación y desistimiento.
- **Condiciones generales de venta** (`[dsmk_condiciones_venta]`): vendedor, precios e IVA, proceso de compra, formas de pago, envío (máx. 30 días), desistimiento configurable (≥ 14 días), excepciones del art. 103, garantía de 3 años, reclamaciones. Se asignan como términos de WooCommerce (casilla del checkout).
- **Derecho de desistimiento** (`[dsmk_desistimiento]`): información según el anexo A y formulario modelo del anexo B; `[dsmk_desistimiento_formulario]` con punto de integración `dsmkc_withdrawal_form` para el plugin de desistimiento (Directiva UE 2023/2673) y alternativa por email.
- **Creación automática de páginas** al guardar cualquier formulario (opción `paginas_auto`), con aviso de las páginas creadas.
- **Textos adicionales** por documento (apartado «Otras condiciones»).
- Pestañas nuevas: Documentos, Servicios, Tienda, Textos adicionales y Herramientas.
- **Herramientas**: autodetección de datos (WooCommerce, pasarelas de pago, textos legales existentes también en Elementor), **briefing para IA en Markdown con un clic**, importación por archivo o **JSON pegado** (acepta respuestas de IA con texto, JSON de 1.x y objetos planos), opción «solo campos vacíos», exportación sin IDs de página.
- API pública `dsmk_legal_get()`, `dsmk_legal_page_url()`, `dsmk_legal_has_consent()`.
- Privacidad: tratamiento «Clientes de servicios»; los tratamientos de tienda y servicios se añaden solos al activar sus documentos.
- Informe: comprobación de todos los documentos activos, términos de WooCommerce y función de desistimiento online.

### Cambiado
- Enlaces del pie (`[dsmk_enlaces_legales]`) con todos los documentos activos.
- Pestañas de cookies renombradas («Cookies: banner», «Cookies: categorías», «Cookies: servicios»).

### Corregido
- Autodetección: los párrafos ya no se pegan al convertir HTML a texto (detección de titular y NIF).

### Pruebas
- PHP: 105/105. Navegador (Chromium): 40/40. Panel: 13 pestañas sin errores. Ver `TESTS.md`.

## [1.0.0] — 2026-11-04 (como «DSMK Cookies»)

Primera versión: banner AEPD, bloqueo previo en dos capas, catálogo de 42 servicios, Consent Mode v2, WP Consent API, escáner, registro de consentimientos, política de cookies / privacidad / aviso legal, informe para Kit Digital, estilos que heredan Elementor.
