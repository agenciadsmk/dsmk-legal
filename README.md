# DSMK Legal

Hub legal para webs WordPress de clientes de DSMK, sin costes por web: aviso legal, política de privacidad, política de cookies con banner y bloqueo (Guía AEPD), términos y condiciones de servicios, condiciones de venta y desistimiento.

## Instalación

1. **Plugins → Añadir nuevo → Subir plugin** → `dsmk-legal-2.0.0.zip` → Activar. Si la web tenía DSMK Cookies, se desactiva solo y se conserva toda su configuración.
2. **DSMK Legal → Herramientas**:
   - **Detectar datos**: aplica lo que encuentre en WooCommerce y en los textos legales actuales.
   - **Rellenar con IA**: descarga el briefing, pégalo en Claude con los datos del cliente (o la URL de la web) y pega el JSON que te devuelva en **Importar**.
3. **Documentos**: activa los que necesite la web. Para cada uno, **usa la página que ya tenga la web** (aparece preseleccionada; marca «Sustituir» para que muestre el texto del plugin) o pulsa **«Crear página nueva»**. Nada se crea sin pulsar un botón.
4. Completa **Titular**, y **Servicios** o **Tienda** si procede. Los textos se actualizan solos.
5. **Cookies: servicios → Escanear la web**.
6. Pon `[dsmk_enlaces_legales]` en el pie (widget Shortcode de Elementor).
7. **Auditoría → «Ejecutar auditoría completa»**: un clic escanea toda la web, la guarda en el histórico y genera el informe técnico en PDF (puntuación, acciones recomendadas, detalle por página, cookies, terceros, consentimientos, entorno). Se repite sola cada semana.

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
