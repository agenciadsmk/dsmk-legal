# DSMK Legal

Hub legal para webs WordPress de clientes de DSMK, sin costes por web: aviso legal, política de privacidad, política de cookies con banner y bloqueo (Guía AEPD), términos y condiciones de servicios, condiciones de venta y desistimiento.

## Instalación

1. **Plugins → Añadir nuevo → Subir plugin** → `dsmk-legal-<versión>.zip` → Activar. Si la web tenía DSMK Cookies, se desactiva solo; se importan los datos del titular y se conserva el registro de consentimientos.
2. **DSMK Legal → Herramientas**:
   - **Detectar datos**: aplica lo que encuentre en WooCommerce y en los textos legales actuales.
   - **Rellenar con IA**: descarga el briefing, pégalo en Claude con los datos del cliente (o la URL de la web) y pega el JSON que te devuelva en **Importar**.
3. **Documentos**: activa los que necesite la web. Para cada uno, **usa la página que ya tenga la web** (aparece preseleccionada; marca «Sustituir» para que muestre el texto del plugin) o pulsa **«Crear página nueva»**. Nada se crea sin pulsar un botón.
4. Completa **Titular**, y **Servicios** o **Tienda** si procede. Los textos se actualizan solos.
5. **Cookies: servicios → Escanear la web**.
6. Pon `[dsmk_enlaces_legales]` en el pie (widget Shortcode de Elementor).
7. **Auditoría → «Ejecutar auditoría completa»**: un clic escanea toda la web, la guarda en el histórico y genera el informe técnico en PDF (puntuación, acciones recomendadas, detalle por página, cookies, terceros, consentimientos, entorno). Se repite sola cada semana.

## Actualizaciones

Cada web consulta cada 12 horas `https://agenciadsmk.github.io/dsmk-legal/info.json`. Cuando hay versión nueva, WordPress muestra el aviso normal («Hay una nueva versión disponible») y, por defecto, la instala sola. Solo se instalan versiones **firmadas por DSMK**, y el ZIP se comprueba con su huella antes de instalarse. Estado y botón «Comprobar ahora» en **Herramientas › Actualizaciones**, donde también se pueden desactivar las automáticas.

**Publicar una versión** (DSMK):

1. Sube la versión en `dsmk-legal.php` (cabecera `Version:` y `DSMKC_VERSION`) y en `tests/php/bootstrap.php`. Añade la entrada `## [X.Y.Z]` en `CHANGELOG.md` y las pruebas en `TESTS.md`.
2. `composer test` en local. Fusiona en `produccion`.
3. `git tag vX.Y.Z && git push origin vX.Y.Z`. La acción **Release** prueba, empaqueta, firma, crea la Release de GitHub y publica en GitHub Pages. En unas horas, todas las webs la ven.

**Configuración inicial del repositorio** (una sola vez):

- **Clave de firma:** en un equipo de DSMK, `php bin/make-signing-key.php`. Escribe la clave pública en el plugin (haz commit de ese cambio) y muestra **una sola vez** la clave privada. Hay que:
  - Guardarla en el gestor de contraseñas de DSMK.
  - Crear con ella el secreto **`UPDATE_SIGNING_KEY`** (Settings › Secrets and variables › Actions).

  Si se pierde, las webs dejarán de aceptar versiones nuevas y habrá que reinstalar a mano en cada una. Sin este paso, la publicación se niega a firmar y el plugin no acepta ninguna actualización.
- **Settings › Pages › Source:** «GitHub Actions».
- **Settings › Environments › github-pages › Deployment branches and tags:** añadir la regla de etiqueta `v*`.
- **Repositorio privado:** GitHub Pages en un repositorio privado necesita el plan Team. Las URL de Pages son públicas aunque el código no lo sea.
- **Servidor propio (opcional):**
  - Secretos `UPDATE_SSH_HOST`, `UPDATE_SSH_USER`, `UPDATE_SSH_KEY` y `UPDATE_SSH_PATH`.
  - Variable `UPDATE_BASE_URL`.
  - En las webs: `define( 'DSMK_LEGAL_UPDATE_URL', 'https://…/info.json' );`, o el ajuste «URL de actualizaciones».
- **Multisite:** activa el plugin para toda la red. La URL y la clave van en constantes de `wp-config.php` y las automáticas se activan en Red › Plugins.

**Rotar la clave de firma** (solo si se filtra):

1. `php bin/make-signing-key.php --force`. Escribe la clave pública nueva en `DSMKC_Updater::PUBLIC_KEY` y muestra la privada nueva.
2. Publica esa versión **todavía firmada con la clave antigua**. Como la clave del plugin ya es la nueva, genera el `info.json` a mano con `DSMK_SIGNING_KEY=<privada antigua> php bin/make-update-json.php … --expected-pubkey=<pública antigua>` y súbelo a la Release y a Pages.
3. Después, cambia el secreto `UPDATE_SIGNING_KEY` por la clave privada nueva.

Si la clave antigua se ha comprometido, cambia el secreto enseguida. Las webs que ya tengan la versión nueva siguen bien; las demás hay que actualizarlas a mano una vez, o definir `DSMK_LEGAL_UPDATE_PUBKEY` en su `wp-config.php`.

## Qué documento usar

- **Web corporativa**: aviso legal + privacidad + cookies.
- **Web que vende servicios** (agencia, estudio, profesional): + términos y condiciones del servicio.
- **Tienda online**: + condiciones de venta + desistimiento. Para la función de desistimiento online (Directiva UE 2023/2673) instala también el plugin de desistimiento de DSMK: se integra solo.

## Elementor

- Abrir el panel de cookies desde cualquier botón: enlace `#dsmk-cookies` o clase `dsmkc-open-settings`.
- El banner y el botón de desistimiento heredan los colores y la tipografía globales del kit.

## Limitaciones

- **AdSense / Ad Manager**: Google exige un gestor de consentimiento certificado con IAB TCF para anuncios personalizados.
- Los textos son plantillas profesionales; en casos especiales (salud, menores, financiero) conviene revisión jurídica.
- Desarrollo y pruebas: `CLAUDE.md` y `TESTS.md`.
