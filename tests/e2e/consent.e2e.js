/**
 * DSMK Legal — Pruebas de extremo a extremo en navegador real (Chromium).
 *
 * Uso:
 *   php -S 127.0.0.1:8765 tests/e2e/router.php &
 *   node tests/e2e/consent.e2e.js [carpeta-capturas]
 *
 * Comprueba con cookies REALES instaladas por rastreadores locales:
 *   1. Sin decisión: banner visible, 0 cookies no técnicas, nada ejecutado,
 *      Consent Mode por defecto denegado, iframe sustituido por aviso.
 *   2. Rechazar: sigue sin cookies, se registra la prueba, no vuelve el banner.
 *   3. Configurar solo analíticas: se activan solo las analíticas (incluida
 *      la inyectada por código), Consent Mode actualizado.
 *   4. Aceptar todas: se activa publicidad e iframe.
 *   5. Revocar: se borran las cookies y se recarga sin rastreadores.
 *   6. Consentimiento puntual desde el aviso del vídeo.
 *   7. Cambio de versión de la política: vuelve el banner.
 *   8. Accesibilidad básica: Escape cierra el panel, foco en el panel.
 *   9. GPC: rechazo automático.
 *  10. Contenido dinámico tras la carga (innerHTML/AJAX): iframe y píxel
 *      <img> bloqueados hasta aceptar (2.2.0).
 *  11. Registro de consentimiento que falla en el servidor: se guarda y se
 *      reintenta en la siguiente página (2.2.0).
 *
 * @package DSMK_Legal
 * @file    tests/e2e/consent.e2e.js
 * @since   1.0.0
 */
const { chromium } = require('playwright');
const path = require('path');

const BASE = process.env.DSMKC_BASE || 'http://127.0.0.1:8765';
const SHOTS = process.argv[2] || null;
let pass = 0;
const fails = [];

function ok(cond, label) {
	if (cond) { pass++; console.log('  ✔ ' + label); } else { fails.push(label); console.log('  ✘ ' + label); }
}

async function cookies(ctx) {
	const list = await ctx.cookies(BASE);
	return Object.fromEntries(list.map((c) => [c.name, c.value]));
}

async function shot(page, name, opts = {}) {
	if (SHOTS) { await page.screenshot({ path: path.join(SHOTS, name + '.png'), ...opts }); }
}

async function log() {
	const r = await fetch(BASE + '/__log');
	return (await r.text()).trim().split('\n').filter(Boolean).map((l) => JSON.parse(l));
}

(async () => {
	await fetch(BASE + '/__reset');
	const browser = await chromium.launch();

	/* -------------------------------------------------------------- 1 */
	console.log('\n[1] Primera visita, sin decisión');
	let ctx = await browser.newContext({ viewport: { width: 1280, height: 800 }, deviceScaleFactor: SHOTS ? 2 : 1 });
	let page = await ctx.newPage();
	const errors = [];
	page.on('pageerror', (e) => errors.push(e.message));
	await page.goto(BASE + '/', { waitUntil: 'networkidle' });
	await page.waitForTimeout(500);
	let c = await cookies(ctx);
	ok(await page.isVisible('.dsmkc-banner'), 'Banner visible');
	ok(await page.isVisible('.dsmkc-banner [data-dsmkc-action="reject"]') && await page.isVisible('.dsmkc-banner [data-dsmkc-action="accept"]'), 'Aceptar y Rechazar visibles en la 1ª capa');
	const sizes = await page.$$eval('.dsmkc-banner .dsmkc-btn--primary', (b) => b.map((x) => { const s = getComputedStyle(x); return [s.backgroundColor, s.color, s.fontSize, s.fontWeight, x.offsetHeight].join('|'); }));
	ok(sizes.length === 2 && sizes[0] === sizes[1], 'Aceptar y Rechazar con idéntico estilo calculado (' + sizes[0] + ')');
	ok(!c._lt_analytics && !c._lt_ads && !c._lt_dyn && !c._lt_video && !c.dsmkc_consent, '0 cookies no técnicas instaladas (ni la de consentimiento)');
	ok(!(await page.evaluate(() => window.__lt_analytics || window.__lt_ads || window.__lt_dyn || window.__inline_tracker_ran)), 'Ningún rastreador ejecutado (externo, en línea ni dinámico)');
	ok(await page.evaluate(() => window.__injector === true), 'El script no rastreador que inyecta otro sí se ejecuta (la web no se rompe)');
	const dyn = await page.evaluate(() => performance.getEntriesByType('resource').some((r) => r.name.includes('dyn-tracker.js')));
	ok(!dyn, 'El script inyectado por código (createElement) no se descarga');
	const cm = await page.evaluate(() => window.dataLayer.filter((e) => e[0] === 'consent').map((e) => [e[1], e[2].analytics_storage, e[2].ad_storage, e[2].ad_user_data]));
	ok(cm.length === 1 && cm[0].join() === 'default,denied,denied,denied', 'Consent Mode v2: por defecto denegado');
	ok(await page.isVisible('.dsmkc-placeholder') && (await page.getAttribute('iframe[title="Vídeo"]', 'src')) === 'about:blank', 'Iframe de terceros sustituido por aviso');
	ok(await page.evaluate(() => document.activeElement === document.body), 'El banner no roba el foco (formato caja)');
	await shot(page, '01-banner-escritorio');

	/* -------------------------------------------------------------- 2 */
	console.log('\n[2] Rechazar todas');
	await page.click('.dsmkc-banner [data-dsmkc-action="reject"]');
	await page.waitForTimeout(600);
	c = await cookies(ctx);
	const consent = JSON.parse(decodeURIComponent(c.dsmkc_consent || '{}'));
	ok(!(await page.isVisible('.dsmkc-banner')), 'Banner oculto');
	ok(await page.isVisible('.dsmkc-revisit'), 'Botón flotante para cambiar la decisión visible');
	ok(consent.c && consent.c.analytics === 0 && consent.c.marketing === 0 && consent.v === 3, 'Cookie técnica de consentimiento: todo rechazado, versión 3');
	ok(!c._lt_analytics && !c._lt_ads && !c._lt_dyn && !c._lt_video, 'Sigue sin cookies no técnicas');
	let entries = await log();
	ok(entries.length === 1 && entries[0].action === 'reject' && /^[0-9a-f-]{36}$/.test(entries[0].id) && entries[0].bh.length === 12, 'Prueba registrada en servidor (acción, ID, huella del banner)');
	await page.reload({ waitUntil: 'networkidle' });
	await page.waitForTimeout(400);
	c = await cookies(ctx);
	ok(!(await page.isVisible('.dsmkc-banner')) && !c._lt_analytics && !c._lt_ads, 'Tras recargar: sin banner y sin rastreadores');

	/* -------------------------------------------------------------- 3 */
	console.log('\n[3] Configurar: solo analíticas');
	await page.click('.dsmkc-revisit');
	await page.waitForTimeout(200);
	ok(await page.isVisible('.dsmkc-panel'), 'Panel de configuración abierto desde el botón flotante');
	ok(await page.evaluate(() => document.activeElement && document.activeElement.classList.contains('dsmkc-close')), 'Foco dentro del panel');
	ok(!(await page.isChecked('[data-dsmkc-toggle="analytics"]')) && !(await page.isChecked('[data-dsmkc-toggle="marketing"]')), 'Interruptores reflejan el rechazo previo (desactivados)');
	await page.click('summary >> nth=1');
	await shot(page, '02-panel-configuracion');
	await page.check('[data-dsmkc-toggle="analytics"]');
	await page.click('.dsmkc-panel [data-dsmkc-action="save"]');
	await page.waitForTimeout(800);
	c = await cookies(ctx);
	ok(c._lt_analytics === '1' && c._lt_dyn === '1', 'Se activan las analíticas, también la inyectada por código');
	ok(!c._lt_ads && !c._lt_video, 'Publicidad y vídeo siguen bloqueados');
	ok(await page.evaluate(() => window.__inline_tracker_ran === true), 'Script en línea analítico ejecutado');
	const upd = await page.evaluate(() => window.dataLayer.filter((e) => e[0] === 'consent').pop());
	ok(upd[1] === 'update' && upd[2].analytics_storage === 'granted' && upd[2].ad_storage === 'denied', 'Consent Mode actualizado: analytics granted, ads denied');
	ok(await page.evaluate(() => window.dataLayer.some((e) => e.event === 'dsmkc_consent_update' && e.dsmkc_analytics === true)), 'Evento dsmkc_consent_update en el dataLayer (para GTM)');

	/* -------------------------------------------------------------- 4 */
	console.log('\n[4] Aceptar todas');
	await page.click('.dsmkc-revisit');
	await page.click('.dsmkc-panel [data-dsmkc-action="accept"]');
	await page.waitForTimeout(800);
	c = await cookies(ctx);
	ok(c._lt_ads === '1', 'Publicidad activada');
	ok((await page.getAttribute('iframe[title="Vídeo"]', 'src')) === '/embed-video.html' && !(await page.isVisible('.dsmkc-placeholder')), 'Iframe cargado y aviso retirado');
	await page.waitForTimeout(500);
	c = await cookies(ctx);
	ok(c._lt_video === '1', 'El contenido incrustado ya puede instalar sus cookies');

	/* -------------------------------------------------------------- 5 */
	console.log('\n[5] Revocar (rechazar todo tras aceptar)');
	await page.click('.dsmkc-revisit');
	await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('.dsmkc-panel [data-dsmkc-action="reject"]')]);
	await page.waitForTimeout(500);
	c = await cookies(ctx);
	ok(!c._lt_analytics && !c._lt_ads && !c._lt_dyn && !c._lt_video, 'Cookies propias borradas al revocar');
	ok(!(await page.evaluate(() => window.__lt_analytics || window.__lt_ads)), 'Recarga automática: ningún rastreador sigue activo');
	entries = await log();
	ok(entries.map((e) => e.action).join(',') === 'reject,custom,accept,reject', 'Historial de pruebas: reject, custom, accept, reject');
	ok(new Set(entries.map((e) => e.id)).size === 1, 'Mismo ID de consentimiento en todo el historial');
	await ctx.close();

	/* -------------------------------------------------------------- 6 */
	console.log('\n[6] Consentimiento puntual desde el aviso del vídeo');
	ctx = await browser.newContext({ viewport: { width: 1440, height: 1100 }, deviceScaleFactor: SHOTS ? 2 : 1 });
	page = await ctx.newPage();
	await page.goto(BASE + '/', { waitUntil: 'networkidle' });
	await page.evaluate(() => window.scrollTo(0, 200));
	await shot(page, '03-aviso-video-bloqueado');
	await page.click('.dsmkc-placeholder [data-dsmkc-grant]');
	await page.waitForTimeout(800);
	c = await cookies(ctx);
	ok((await page.getAttribute('iframe[title="Vídeo"]', 'src')) === '/embed-video.html', 'Vídeo cargado tras aceptar ese servicio');
	ok(!c._lt_ads && !c._lt_analytics, 'El resto de servicios siguen bloqueados');
	ok(!(await page.isVisible('.dsmkc-banner')), 'Cuenta como decisión: el banner se cierra');

	/* -------------------------------------------------------------- 7 */
	console.log('\n[7] Nueva versión de la política');
	await page.goto(BASE + '/?v=4', { waitUntil: 'networkidle' });
	await page.waitForTimeout(300);
	ok(await page.isVisible('.dsmkc-banner'), 'Con versión 4 el consentimiento de la versión 3 no vale: vuelve el banner');
	ok((await page.getAttribute('iframe[title="Vídeo"]', 'src')) === 'about:blank', 'Y vuelve a bloquear el vídeo');

	/* -------------------------------------------------------------- 8 */
	console.log('\n[8] Teclado y enlaces de pie');
	await page.click('footer a[href="#dsmk-cookies"]');
	await page.waitForTimeout(150);
	ok(await page.isVisible('.dsmkc-panel'), 'Enlace «#dsmk-cookies» del pie abre el panel');
	await page.keyboard.press('Escape');
	await page.waitForTimeout(150);
	ok(!(await page.isVisible('.dsmkc-panel')) && await page.isVisible('.dsmkc-banner'), 'Escape cierra el panel y, sin decisión, vuelve la 1ª capa');
	await ctx.close();

	/* -------------------------------------------------------------- 9 */
	console.log('\n[9] Global Privacy Control');
	ctx = await browser.newContext();
	await ctx.addInitScript(() => Object.defineProperty(navigator, 'globalPrivacyControl', { get: () => true }));
	page = await ctx.newPage();
	await page.goto(BASE + '/?gpc=1', { waitUntil: 'networkidle' });
	await page.waitForTimeout(400);
	c = await cookies(ctx);
	ok(!(await page.isVisible('.dsmkc-banner')) && !!c.dsmkc_consent && !c._lt_analytics, 'Con GPC activado en ajustes: rechazo automático sin banner');
	await ctx.close();


	/* -------------------------------------------------------------- 10 */
	console.log('\n[10] Contenido insertado después de cargar la página (2.2.0)');
	await fetch(BASE + '/__reset');
	ctx = await browser.newContext();
	page = await ctx.newPage();
	page.on('pageerror', (e) => errors.push(e.message));
	await page.goto(BASE + '/', { waitUntil: 'networkidle' });
	await page.evaluate(() => {
		document.querySelector('main').insertAdjacentHTML('beforeend', '<div class="ajax"><iframe id="dynf" src="/embed-video.html" title="Vídeo AJAX"></iframe><img id="dynimg" src="/pixel.gif?ajax=1" alt=""></div>');
		// Patrón de jQuery (buildFragment): innerHTML en un div fuera del documento.
		var tmp = document.createElement('div');
		tmp.innerHTML = '<img id="jqimg" src=\'/pixel.gif?jq=1\' alt="">';
		document.body.appendChild(tmp.firstChild);
	});
	await page.waitForTimeout(800);
	c = await cookies(ctx);
	const dynState = await page.evaluate(() => ({
		f: document.getElementById('dynf').getAttribute('src'),
		fd: document.getElementById('dynf').getAttribute('data-dsmkc-src'),
		i: document.getElementById('dynimg').getAttribute('src'),
		id: document.getElementById('dynimg').getAttribute('data-dsmkc-src'),
		jq: document.getElementById('jqimg').getAttribute('src')
	}));
	ok(dynState.f === 'about:blank' && dynState.fd === '/embed-video.html' && !c._lt_video, 'Iframe insertado por innerHTML tras la carga: bloqueado, sin cookie del vídeo');
	ok(dynState.i === null && dynState.id === '/pixel.gif?ajax=1' && dynState.jq === null, 'Píxeles <img> insertados tras la carga (insertAdjacentHTML y patrón jQuery): sin src hasta el consentimiento');
	ok(!c._lt_px, 'El píxel no ha instalado su cookie (' + (c._lt_px ? 'la petición salió antes de neutralizarlo' : 'ok') + ')');
	await page.click('.dsmkc-banner [data-dsmkc-action="accept"]');
	await page.waitForTimeout(1200);
	c = await cookies(ctx);
	ok(!!c._lt_px && (await page.getAttribute('#dynimg', 'src')) === '/pixel.gif?ajax=1', 'Tras aceptar, el píxel se carga y el iframe se activa');
	await ctx.close();

	/* -------------------------------------------------------------- 11 */
	console.log('\n[11] Fallo del registro de consentimiento: reintento (2.2.0)');
	await fetch(BASE + '/__reset');
	await fetch(BASE + '/__faillog?on=1');
	ctx = await browser.newContext();
	page = await ctx.newPage();
	page.on('pageerror', (e) => errors.push(e.message));
	await page.goto(BASE + '/', { waitUntil: 'networkidle' });
	await page.click('.dsmkc-banner [data-dsmkc-action="reject"]');
	await page.waitForTimeout(800);
	const pending = await page.evaluate(() => JSON.parse(localStorage.getItem('dsmkc_pending') || '[]'));
	ok(pending.length === 1 && pending[0].action === 'reject' && (await log()).length === 0, 'El servidor responde 500: la prueba queda pendiente en el navegador');
	await fetch(BASE + '/__faillog?on=0');
	await page.goto(BASE + '/politica-de-cookies/', { waitUntil: 'networkidle' });
	await page.waitForTimeout(800);
	const retried = await log();
	ok(retried.length === 1 && retried[0].action === 'reject' && (await page.evaluate(() => localStorage.getItem('dsmkc_pending'))) === null, 'En la siguiente página se reenvía y se guarda; la cola queda vacía');
	await ctx.close();

	/* -------------------------------------------------------------- capturas */
	if (SHOTS) {
		ctx = await browser.newContext({ ...require('playwright').devices['iPhone 13'] });
		page = await ctx.newPage();
		await page.goto(BASE + '/', { waitUntil: 'networkidle' });
		await shot(page, '04-banner-movil');
		await ctx.close();
		ctx = await browser.newContext({ viewport: { width: 1280, height: 800 }, deviceScaleFactor: 2 });
		page = await ctx.newPage();
		await page.goto(BASE + '/?pos=barra_inferior&mode=personalizado', { waitUntil: 'networkidle' });
		await shot(page, '05-barra-inferior-personalizado');
		await page.goto(BASE + '/politica-de-cookies/', { waitUntil: 'networkidle' });
		await page.click('.dsmkc-banner [data-dsmkc-action="reject"]');
		await shot(page, '06-politica-de-cookies', { fullPage: true });
		await ctx.close();
	}

	console.log('\nErrores JS en página: ' + (errors.length ? errors.join(' | ') : 'ninguno'));
	ok(errors.length === 0, 'Sin errores de JavaScript');
	await browser.close();
	console.log('\nResultado: ' + pass + '/' + (pass + fails.length) + ' correctas');
	if (fails.length) { console.log('Fallos:\n - ' + fails.join('\n - ')); process.exit(1); }
})();
