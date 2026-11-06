# Changelog — DSMK Legal

Formato: versión semántica (MAYOR.MENOR.PARCHE). Cada entrada indica qué cambia, por qué y qué pruebas se pasaron (detalle en `TESTS.md`).

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
