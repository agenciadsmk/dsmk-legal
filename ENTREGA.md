# Entrega a Arturo — DSMK Legal 2.3.0

**Fecha:** 04/11/2026.
**Objetivo:** pasar el plugin al protocolo de desarrollo de DSMK (repositorio en GitHub, estándares, CI y pruebas) y dejarlo listo para instalarlo en las webs de clientes.

## 1. Qué es

Es un hub legal para WordPress. Incluye:

- Aviso legal, privacidad, cookies (banner, bloqueo previo y registro de consentimientos), términos de servicio, condiciones de venta y desistimiento.
- Auditoría técnica con histórico e informe en PDF.
- Importación y exportación en JSON, con un briefing para rellenarlo con IA.

`CLAUDE.md` es la referencia técnica completa: arquitectura, reglas legales que no se pueden romper, decisiones de seguridad, API y filtros. **Léelo antes de tocar código.**

## 2. Estado

| Área | Estado |
|---|---|
| Funcionalidad | Completa (ver `CHANGELOG.md`, de 1.0.0 a 2.2.0). |
| Pruebas de lógica (PHP, sin WordPress) | 196/196 — `php tests/php/run-tests.php` |
| Pruebas en navegador real (Chromium) | 46/46 — ver `CLAUDE.md` › Pruebas |
| Revisión de seguridad | Hecha en 2.2.0 por un revisor independiente; los 11 hallazgos están corregidos o documentados (ver `CHANGELOG.md`). |
| WordPress real | **Sin probar todavía en staging.** Es lo primero que hay que hacer (punto 4). |
| PHP_CodeSniffer (WPCS) | **No se ha podido pasar:** el entorno donde se hizo no permitía instalar dependencias. La configuración está lista (`phpcs.xml.dist`). |

## 3. Repositorio

```bash
# El código ya está en agenciadsmk/dsmk-legal (rama claude/actualizador-2.3.0, con PR a produccion)
git clone https://github.com/agenciadsmk/dsmk-legal && cd dsmk-legal

composer install      # WPCS + PHPCompatibilityWP (solo desarrollo)
composer lint         # Primera pasada de estándares (ver 4.2)
composer test         # 160 pruebas
composer build        # dist/dsmk-legal-2.3.0.zip
```

La CI (`.github/workflows/ci.yml`) pasa sintaxis en PHP 7.4, 8.0 y 8.3 y las pruebas en cada push. WPCS va en modo informativo (`continue-on-error`) hasta limpiar la base; después, quita esa línea.

## 4. Tareas para dejarlo en protocolo

1. **Staging real.** Usa WordPress 6.7+ con Elementor, WooCommerce y una caché de página (LiteSpeed o WP Rocket).
   - Activa el plugin y rellena el Titular con «Detectar datos».
   - Asigna las páginas con los botones de Documentos.
   - Pulsa «Escanear la web» y luego «Ejecutar auditoría completa».
   - **Comprobación clave de 2.2.0:** después de escanear, abre una ventana de incógnito. Debe seguir viéndose el banner y no debe haber cookies no técnicas. Esto confirma que la caché no guarda el HTML del escáner.
   - Mira `debug.log` con `WP_DEBUG` activado: no debe haber avisos.
2. **WPCS.** Ejecuta `composer lint` y corrige lo que salga (`composer lint:fix` arregla el formato). El código ya sigue las reglas de seguridad de WPCS (nonces, escapado, `prepare`, `wp_unslash`), así que se esperan sobre todo avisos de formato.
3. **Adaptar al protocolo general.** Ajusta la cabecera de archivos, el formato de `CHANGELOG.md` y `TESTS.md` y el nombre de las ramas a lo que fije el protocolo. Si cambias el formato de anotaciones, cámbialo también en `CLAUDE.md` › Reglas de código, para que la IA lo siga.
4. **Integración con tu plugin de desistimiento.** El contrato está en `CLAUDE.md` › Integración. Tu plugin solo tiene que engancharse al filtro `dsmkc_withdrawal_form`, o marcar el documento como `external`. No hace falta tocar este plugin.
5. **Traducciones.** Ya está `languages/dsmk-legal.pot`. Si hace falta otro idioma, genera el `.po`/`.mo` desde ahí. Con WP-CLI, `wp i18n make-pot` sustituye a `bin/make-pot.php`.
6. **Siguientes versiones.** Cada cambio lleva su entrada en `CHANGELOG.md` y `TESTS.md`. Para publicar, se crea la etiqueta `vX.Y.Z` (ver 4 bis).

## 4 bis. Actualizaciones (2.3.0)

Desde la 2.3.0 las webs se actualizan solas desde GitHub Pages (ver `README.md` › Actualizaciones). Para dejarlo funcionando:

1. Fusiona la rama `claude/actualizador-2.3.0` en `produccion`.
2. En el repositorio:
   - **Settings › Pages › Source:** «GitHub Actions».
   - **Settings › Environments › github-pages › Deployment branches and tags:** añadir la regla de etiqueta `v*`.
3. `git tag v2.3.0 && git push origin v2.3.0`, y revisa que la acción **Release** acabe en verde y que `https://agenciadsmk.github.io/dsmk-legal/info.json` responda.
4. Las webs que ya tienen la 2.2.1 o anteriores hay que actualizarlas a mano **una sola vez**, con el ZIP de la Release v2.3.0. Las versiones siguientes llegan solas.
5. **Decidid si el repositorio debe ser privado** (ahora es público). Si lo hacéis privado, GitHub Pages necesita el plan Team, o hay que usar el servidor propio por SSH (secretos `UPDATE_SSH_*`).

## 5. Límites conocidos (documentados, no son fallos)

- **AdSense / Ad Manager con anuncios personalizados:** Google exige un CMP certificado con IAB TCF; este plugin no lo es. Sin anuncios personalizados no hay problema.
- **Lo que el bloqueo en navegador no puede interceptar:** píxeles creados con `new Image().src`, `document.write` posterior a la carga y `DOMParser`. No es grave, porque los scripts que hacen eso son rastreadores y ya están bloqueados en origen.
- **«Aceptar todas»** solo activa lo declarado en el banner. Un servicio nuevo sin escanear sigue bloqueado hasta el siguiente escaneo (es intencionado: el consentimiento debe ser informado). Hay un escaneo semanal automático.
- **Textos legales:** son plantillas profesionales. En sectores especiales (salud, menores, financiero) conviene una revisión jurídica.
- **Prefijo interno `dsmkc`:** se hereda de «DSMK Cookies» 1.x. **No renombrar**, porque se perderían los ajustes y los registros de consentimiento de las webs ya instaladas.

## 6. Contenido de esta entrega

```
dsmk-legal/                 Repositorio completo (código, pruebas, documentación, CI)
  ENTREGA.md                Este documento
  CLAUDE.md                 Referencia técnica para trabajar con IA o a mano
  CHANGELOG.md · TESTS.md   Historial de versiones y registro de pruebas
  dist/dsmk-legal-2.3.0.zip ZIP instalable (sin pruebas ni archivos de desarrollo)
```
