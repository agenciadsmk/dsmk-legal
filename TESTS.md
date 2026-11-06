# Registro de pruebas — DSMK Legal

## v2.3.0 — 2026-11-07

| Suite | Resultado |
|---|---|
| `php tests/php/run-tests.php` | **213/213** |
| `node tests/e2e/consent.e2e.js` | **46/46** |
| Pestaña Herramientas renderizada (tarjeta de actualizaciones, clave como contraseña) | OK |
| Publicación real en local: `bin/build.sh` + `make-update-json` con la clave de producción, validado con la `PUBLIC_KEY` del plugin en un proceso aparte | OK |

**Nuevas [17]: actualizador (46 pruebas).**

| Qué se prueba | Comprobaciones |
|---|---|
| Firma | La firmada con la clave de DSMK es válida. Se rechaza: sin firma, con otra clave (servidor comprometido), con la huella cambiada y con la versión cambiada. |
| JSON | Se rechaza: sin SHA-256, con descarga http, de otro plugin o con una versión que no es X.Y.Z. El changelog se limpia. |
| URL | GitHub Pages por defecto; http rechazada; servidor propio; en multisite se ignora el ajuste del subsitio. |
| Comprobación de WordPress | Aviso con la huella junto al paquete. Caché. Tras actualizarse sola no se vuelve a ofrecer la misma versión. Con la misma versión, sin aviso pero con el interruptor de automáticas. Servidor caído y caché del error. |
| Detalles | Muestra versión y changelog, `external`; no toca otros plugins. |
| Descarga | ZIP verificado (sin depender de la caché del JSON). ZIP alterado: no se instala y se borra el temporal. Sin huella: falla cerrado, sin descargar. Otros plugins: sin peticiones extra. |
| Carpeta | La normal no se toca; otros plugins tampoco; en `dsmk-legal-main/` se respeta la carpeta. |
| Clave | Sin clave no se envía nada. Con clave, solo al host del JSON y sin redirecciones; nunca a otro host. |
| Exportación e importación | La clave, la URL y las automáticas ni se exportan ni se importan, y la clave no sale en el briefing de IA. |
| Automáticas | Por defecto solo para este plugin; en multisite manda la red; desactivadas, manda WordPress. |
| «Comprobar de nuevo» | Consulta también nuestro servidor, una vez por petición. |
| `Update URI` y clave pública | Presentes en el plugin. |
| `make-update-json` | JSON firmado aceptado por el actualizador, con el changelog en HTML. Se niega con URL http, con un ZIP de otra versión o sin clave de firma. |

**Revisión independiente:** encontró 11 problemas (2 altos, 4 medios y 5 bajos); están corregidos y cubiertos por estas pruebas. El detalle está en el CHANGELOG.

**Fallos encontrados en esta ronda:**
- Dos pruebas de `make-update-json` fallaron hasta que existió la entrada 2.3.0 del CHANGELOG. Es el comportamiento esperado: no publica una versión sin documentar.
- El stub `wp_kses_post` no filtraba nada; ahora quita `<script>`/`<style>`, como el real.

**Pendiente (no automatizable aquí):**
- La primera publicación real (`v2.3.0`).
- En staging, comprobar el aviso y la actualización con un clic de 2.3.0 a 2.3.1.
- La 2.2.1 y anteriores no tienen actualizador: la 2.3.0 se instala a mano una vez en cada web.

## v2.2.1 — 2026-11-06

| Suite | Resultado |
|---|---|
| `php tests/php/run-tests.php` | **167/167** |
| `node tests/e2e/consent.e2e.js` | **46/46** |

**Nuevas [16]: convivencia con «DSMK Cookies» 1.0.0.**
- `DSMKC_DB_VERSION` = «2» en el archivo principal.
- Importa titular, NIF y domicilio sin pisar campos ya rellenos.
- Quita las claves anidadas y la exportación sale limpia.
- No toca nuestra propia tabla.
- En la tabla de DSMK Cookies, una sola consulta rellena categorías y versión sin tocar la acción.

**Fallo encontrado:** la primera versión de la prueba de `DSMKC_DB_VERSION` leía la constante del bootstrap de pruebas en vez de la del plugin. Ahora lee `dsmk-legal.php`.

## v2.2.0 — 2026-11-04

| Suite | Resultado |
|---|---|
| `php tests/php/run-tests.php` | **160/160** |
| `node tests/e2e/consent.e2e.js` | **46/46**, 0 errores JS |
| `php -l` en todos los archivos (PHP 8.3) | Sin errores |
| Docblocks con `@since` en todas las funciones (tokenizer) | 0 pendientes |
| Llamadas de traducción fuera de funciones (tokenizer) | 0 |
| `bin/build.sh` (ZIP sin archivos de desarrollo) | OK |

**Nuevas [15]: revisión de seguridad.**

| Hallazgo | Qué se comprueba |
|---|---|
| Flags de constructores visuales | `?bricks=1` de un anónimo no desactiva el bloqueo; el de un editor sí. |
| Token de escaneo efímero | Sin escaneo en curso no hay token válido; durante el escaneo sí; se reutiliza mientras dura; ya no se guarda en `dsmkc_data`. |
| Escaneo en navegador | El nonce sin sesión no sirve; el token del servidor no vale en la URL. |
| Peticiones del escáner | Parámetro anticaché. |
| Redirecciones | Se siguen las del mismo dominio (http→https, www) con `redirection = 0`; la redirección a otro dominio no se sigue. |
| Ajustes | Las barras invertidas del CSS se conservan; un array en un campo de texto, número o selección no provoca avisos. |
| Registro: validación | Id con `\n` final rechazado; servicios recortados y valores no escalares ignorados sin avisos. |
| Registro: errores y límite | 500 si la base de datos falla; límite por IP completa que no afecta a otra IP del mismo /24; IP guardada anonimizada. |
| Consent Mode | Avanzado: Google libre y el píxel de Meta bloqueado. Básico: GTM bloqueado. Patrones de `<img>` separados. |
| Elementos sin clasificar | Tope de 200. |

**Nuevas E2E (navegador real).**
- [10] Contenido insertado después de cargar la página:
  - Iframe con insertAdjacentHTML bloqueado y sin cookie.
  - Píxeles `<img>` (insertAdjacentHTML y patrón jQuery) sin `src` y sin cookie.
  - Al aceptar, se activan.
- [11] El registro responde 500: la prueba queda pendiente en el navegador; en la siguiente página se reenvía y la cola se vacía.

**Fallos encontrados en esta ronda:**
- Un hallazgo del revisor se confirmó con una prueba que falló: un píxel `<img>` insertado por innerHTML se descargaba antes de que el MutationObserver pudiera actuar. Se ha resuelto filtrando la cadena HTML. La prueba [10] lo cubre.
- Durante esta ronda también fallaron y se corrigieron dos ajustes de las pruebas: un transient que quedaba de la sección [14] y un nombre de variable duplicado.

**Pendiente (no automatizable aquí):** prueba en una web real de staging con caché de página activa. Comprobar que, tras «Escanear la web», una ventana de incógnito sigue viendo el banner y ningún rastreador.

## v2.1.1 — 2026-11-04

- Arranque movido a `init` (prioridad 0) para evitar el aviso de traducciones cargadas antes de tiempo (WP 6.7+).
- Comprobación estática con tokenizer: ninguna llamada de traducción fuera de funciones.
- PHP 137/137 · E2E 40/40.

## v2.1.0 — 2026-11-04

| Suite | Resultado |
|---|---|
| `php tests/php/run-tests.php` | **137/137** |
| `node tests/e2e/consent.e2e.js` | **40/40**, 0 errores JS |
| Render de las 13 pestañas del panel (stubs) | OK |
| Informe técnico (`tests/php/render-report.php`) | 12 secciones, 8 páginas A4, sin avisos PHP |

Nuevas [14] con una web simulada (`tests/php/fixture-site.php`): análisis de página (0 fugas tras el bloqueador, banner, cookies del servidor técnicas frente a no técnicas, formularios con y sin información de privacidad ignorando el buscador, campos personales, Google Fonts y recursos de terceros sin contar lo bloqueado, enlace legal que falta en el pie); auditoría completa (servicios con el escáner, usuario, origen, puntuación, comprobaciones de configuración y de auditoría, estados, áreas); **fuga real simulada** (píxel de Meta sin bloquear + cookie _ga) detectada como «no cumple»; histórico (orden, consulta por ID, detalle solo en las 10 últimas); puntuación; declaración y el informe sin referencias a Kit Digital, Red.es ni Cámara de Comercio.

Fallos encontrados en esta ronda: ninguno en el plugin (dos expectativas de la prueba ajustadas). Concordancia de plural corregida («Faltan 1 enlaces»).

## v2.0.1 — 2026-11-04

| Suite | Resultado |
|---|---|
| `php tests/php/run-tests.php` | **119/119** |
| `node tests/e2e/consent.e2e.js` | **40/40**, 0 errores JS |
| Render de las 13 pestañas del panel (stubs) | OK |

Nuevas [13]: creación automática desactivada por defecto y al guardar; migración desde 2.0.0 una sola vez; el importador no la activa; detección de páginas existentes («Aviso legal», «Política de Privacidad», «Condiciones generales de venta» sin confundirla con términos de servicio); «crear las que faltan» no duplica; botón por documento; asignar sin sustituir conserva el texto; sustituir guarda copia y desactiva Elementor solo en esa página; no asigna páginas inexistentes. Panel: botones por documento, sugerencia preseleccionada y contador de páginas a crear.

## v2.0.0 — 2026-11-04

Entorno: PHP 8.3.6 (CLI), Node 22, Chromium (Playwright). Clases reales del plugin con funciones de WordPress simuladas (`tests/php/bootstrap.php`, con filtros reales y creación de páginas en memoria) y web de prueba con rastreadores locales que instalan cookies reales (`tests/e2e/router.php`).

| Suite | Resultado |
|---|---|
| Sintaxis PHP (`php -l`, 19 archivos) | OK |
| Sintaxis JS (`node --check`, 3 archivos) | OK |
| `php tests/php/run-tests.php` | **105/105** |
| `node tests/e2e/consent.e2e.js` | **40/40**, 0 errores JS |
| Render de las 13 pestañas del panel (stubs) | OK, sin errores |

### Nuevo en 2.0 (pruebas [8]–[12])

- **Documentos**: por defecto aviso, privacidad y cookies; al activar términos, condiciones y desistimiento se crean solo sus páginas, con su shortcode, asignadas en ajustes; idempotente; privacidad asignada a WordPress; condiciones asignadas como términos de WooCommerce; modo externo no crea página; otros plugins pueden registrar documentos; el pie solo enlaza documentos activos.
- **Términos de servicio**: B2B (intereses de demora, límite de responsabilidad, sin desistimiento) frente a consumidores (14 días, excepción art. 103.a); cesión frente a licencia; portfolio; jurisdicción.
- **Condiciones de venta**: formas de pago, plazo de desistimiento ampliado, gastos de devolución, garantía de 3 años, entrega máxima de 30 días, excepciones, enlace a desistimiento, sin enlace ODR.
- **Desistimiento**: formulario modelo del anexo B; botón por email; sustitución por el formulario del plugin de desistimiento vía `dsmkc_withdrawal_form`.
- **Herramientas**: extracción de JSON de una respuesta de IA; «solo campos vacíos»; normalización; descarte de valores no permitidos; IDs de página y campos desconocidos ignorados e informados; importación de JSON de 1.x; exportación; briefing IA con tipos, valores permitidos e instrucciones; autodetección de NIF, titular, forma jurídica, datos registrales (aviso legal hecho con Elementor), dirección y pasarelas de WooCommerce; aplicación selectiva.

### Fallo encontrado y corregido en esta ronda
- La autodetección pegaba los párrafos al quitar el HTML («Taller Ejemplo SLUNIF: …») y detectaba mal titular y NIF. Corregido insertando saltos de línea entre bloques.

### Pendiente (no ejecutable en este entorno)
- **Instalación real en WordPress** (activación, sustitución de DSMK Cookies, creación de páginas reales, panel completo, escáner por loopback, cron). Hacerla en staging antes del despliegue.
- Probar con Elementor Pro, WP Rocket / LiteSpeed, TranslatePress y WooCommerce reales.
- Integración con el plugin de desistimiento de Arturo (contrato definido en `CLAUDE.md`).

## v1.0.0 — 2026-11-04 (DSMK Cookies)

PHP 59/59, navegador 40/40. Ver CHANGELOG.
