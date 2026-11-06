/**
 * DSMK Legal — Gestión del consentimiento en el navegador.
 *
 * @package   DSMK_Legal
 * @file      assets/js/dsmkc-consent.js
 * @since     1.0.0
 * @author    DSMK Agencia Digital <hola@dsmk.es>
 * @license   GPL-2.0-or-later
 *
 * Responsabilidad:
 *   - Mostrar el banner si no hay consentimiento válido (o ha caducado / ha
 *     cambiado la política).
 *   - Aceptar todo, rechazar todo o guardar una selección por categorías.
 *   - Consentimiento puntual por servicio desde los contenidos bloqueados
 *     (vídeos, mapas...).
 *   - Activar scripts, iframes y píxeles bloqueados cuando hay consentimiento.
 *   - Revocación efectiva: borra las cookies propias de las categorías
 *     retiradas y recarga la página (lo ya ejecutado no se puede "descargar").
 *   - Comunicar el consentimiento: Google Consent Mode v2, dataLayer
 *     (evento dsmkc_consent_update), WP Consent API y evento DOM dsmkc:consent.
 *   - Registrar la prueba del consentimiento en el servidor (REST).
 *
 * Accesibilidad: diálogos con role="dialog", foco gestionado en el panel,
 * Escape para cerrar, interruptores con role="switch".
 *
 * API pública: window.DSMKCookies.open(), .consent(), .allowed(cat), .accept(), .reject().
 * Cualquier enlace a «#dsmk-cookies» o elemento con clase «dsmkc-open-settings»
 * abre el panel (útil en botones de Elementor).
 *
 * Requisitos: ES5, sin dependencias.
 */
(function (w, d) {
	'use strict';

	var C = w.dsmkcConfig || {};
	var H = w.dsmkcHead || { allowed: function () { return false; }, queue: [], consent: null };
	var CATS = C.cats || [];
	var root, banner, panel, overlay, revisit, live, lastFocus;

	/* ------------------------------------------------------------------
	 * Utilidades
	 * ------------------------------------------------------------------ */
	function $$(sel, ctx) { return Array.prototype.slice.call((ctx || d).querySelectorAll(sel)); }

	function esc(s) {
		return String(s).replace(/[&<>"']/g, function (ch) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
		});
	}

	function uuid() {
		if (w.crypto && typeof w.crypto.randomUUID === 'function') { return w.crypto.randomUUID(); }
		var b = new Uint8Array(16);
		(w.crypto || w.msCrypto).getRandomValues(b);
		b[6] = (b[6] & 15) | 64;
		b[8] = (b[8] & 63) | 128;
		var h = '';
		for (var i = 0; i < 16; i++) { h += (b[i] + 256).toString(16).slice(1); if (i === 3 || i === 5 || i === 7 || i === 9) { h += '-'; } }
		return h;
	}

	function closest(el, sel) {
		while (el && el.nodeType === 1) {
			if ((el.matches || el.msMatchesSelector).call(el, sel)) { return el; }
			el = el.parentNode;
		}
		return null;
	}

	/* ------------------------------------------------------------------
	 * Persistencia
	 * ------------------------------------------------------------------ */

	/**
	 * Guarda el consentimiento en la cookie técnica dsmkc_consent.
	 *
	 * @param {Object} c Consentimiento.
	 */
	function store(c) {
		var secure = w.location.protocol === 'https:' ? '; Secure' : '';
		d.cookie = C.cookie + '=' + encodeURIComponent(JSON.stringify(c)) + '; Max-Age=' + (C.days * 86400) + '; Path=/; SameSite=Lax' + secure;
		H.consent = c;
	}

	/**
	 * Construye un objeto de consentimiento.
	 *
	 * @param {string[]} cats     Categorías aceptadas.
	 * @param {string[]} services Servicios aceptados de forma puntual.
	 * @return {Object}
	 */
	function build(cats, services) {
		var prev = H.consent;
		var c = { v: C.v, id: (prev && prev.id) || uuid(), t: new Date().getTime(), c: {}, s: services || [] };
		for (var i = 0; i < CATS.length; i++) { c.c[CATS[i]] = cats.indexOf(CATS[i]) !== -1 ? 1 : 0; }
		return c;
	}

	/**
	 * Envía la prueba del consentimiento al servidor.
	 *
	 * Si el servidor no la guarda (red, 429, 5xx) se deja en localStorage y se
	 * reintenta en la siguiente página (logPending), para no perder la prueba.
	 *
	 * @param {string} action accept|reject|custom|service|gpc.
	 * @param {Object} c      Consentimiento.
	 */
	function log(action, c) {
		if (!C.log || !C.rest || !w.fetch) { return; }
		send({ id: c.id, action: action, c: c.c, s: c.s, v: c.v, bh: C.bh, url: w.location.pathname });
	}

	/**
	 * POST al endpoint; en caso de fallo guarda el cuerpo para reintentarlo.
	 *
	 * @param {Object} body Datos del registro.
	 */
	function send(body) {
		try {
			w.fetch(C.rest, {
				method: 'POST',
				credentials: 'omit',
				keepalive: true,
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(body)
			}).then(function (r) {
				if (!r.ok && r.status !== 400) { keep(body); }
			})['catch'](function () { keep(body); });
		} catch (e) { /* El registro nunca debe romper la navegación. */ }
	}

	/**
	 * Guarda un registro pendiente (máx. 5; los más recientes).
	 *
	 * @param {Object} body Datos del registro.
	 */
	function keep(body) {
		try {
			var q = JSON.parse(w.localStorage.getItem('dsmkc_pending') || '[]');
			q.push(body);
			w.localStorage.setItem('dsmkc_pending', JSON.stringify(q.slice(-5)));
		} catch (e) { /* Sin almacenamiento: no se puede reintentar. */ }
	}

	/** Reenvía los registros pendientes de visitas anteriores. */
	function logPending() {
		if (!C.log || !C.rest || !w.fetch) { return; }
		var q;
		try {
			q = JSON.parse(w.localStorage.getItem('dsmkc_pending') || '[]');
			w.localStorage.removeItem('dsmkc_pending');
		} catch (e) { return; }
		for (var i = 0; i < q.length; i++) { if (q[i] && q[i].id) { send(q[i]); } }
	}

	/* ------------------------------------------------------------------
	 * Señales a terceros
	 * ------------------------------------------------------------------ */
	function signal(c) {
		if (C.cm && typeof w.gtag === 'function' && H.cmState) {
			w.gtag('consent', 'update', H.cmState(c));
		}
		w.dataLayer = w.dataLayer || [];
		w.dataLayer.push({
			event: 'dsmkc_consent_update',
			dsmkc_preferences: !!c.c.preferences,
			dsmkc_analytics: !!c.c.analytics,
			dsmkc_marketing: !!c.c.marketing,
			dsmkc_services: (c.s || []).join(',')
		});
		if (C.wpca && typeof w.wp_set_consent === 'function') {
			var map = { preferences: ['preferences'], analytics: ['statistics', 'statistics-anonymous'], marketing: ['marketing'] };
			w.wp_set_consent('functional', 'allow');
			for (var k in map) {
				if (Object.prototype.hasOwnProperty.call(map, k)) {
					for (var i = 0; i < map[k].length; i++) { w.wp_set_consent(map[k][i], c.c[k] ? 'allow' : 'deny'); }
				}
			}
		}
		var ev;
		try { ev = new CustomEvent('dsmkc:consent', { detail: c }); } catch (e) { ev = d.createEvent('CustomEvent'); ev.initCustomEvent('dsmkc:consent', false, false, c); }
		d.dispatchEvent(ev);
	}

	/* ------------------------------------------------------------------
	 * Activación de elementos bloqueados
	 * ------------------------------------------------------------------ */

	/**
	 * Sustituye un <script type="text/plain"> bloqueado por uno ejecutable.
	 *
	 * @param {HTMLScriptElement} el Script bloqueado.
	 */
	function activateScript(el) {
		if (el.getAttribute('data-dsmkc-done')) { return; }
		el.setAttribute('data-dsmkc-done', '1');
		var s = H.ce ? H.ce('script') : d.createElement('script');
		for (var i = 0; i < el.attributes.length; i++) {
			var a = el.attributes[i];
			if (a.name === 'type' || a.name === 'src' || a.name.indexOf('data-dsmkc-') === 0) { continue; }
			s.setAttribute(a.name, a.value);
		}
		var type = el.getAttribute('data-dsmkc-type');
		if (type) { s.type = type; }
		var src = el.getAttribute('data-dsmkc-src');
		if (src) {
			if (!el.hasAttribute('async')) { s.async = false; } // Mantiene el orden de ejecución.
			s.src = src;
		} else {
			s.text = el.text || el.textContent || '';
		}
		if (el.parentNode) {
			el.parentNode.insertBefore(s, el.nextSibling);
			el.parentNode.removeChild(el);
		} else {
			(d.head || d.body).appendChild(s);
		}
	}

	/**
	 * Activa todo lo bloqueado que ahora está permitido.
	 */
	function release() {
		var scripts = $$('script[data-dsmkc-category]');
		for (var q = 0; q < H.queue.length; q++) {
			if (scripts.indexOf(H.queue[q]) === -1) { scripts.push(H.queue[q]); }
		}
		scripts.forEach(function (el) {
			if (H.allowed(el.getAttribute('data-dsmkc-category'), el.getAttribute('data-dsmkc-service'))) { activateScript(el); }
		});
		$$('iframe[data-dsmkc-src], img[data-dsmkc-src]').forEach(function (el) {
			if (!H.allowed(el.getAttribute('data-dsmkc-category'), el.getAttribute('data-dsmkc-service'))) { return; }
			var src = el.getAttribute('data-dsmkc-src');
			el.removeAttribute('data-dsmkc-src');
			el.className = (el.className || '').replace(/\s*dsmkc-blocked/g, '');
			if (el._dsmkcPh && el._dsmkcPh.parentNode) {
				el._dsmkcPh.parentNode.removeChild(el._dsmkcPh);
				el.style.display = el._dsmkcDisplay || '';
			}
			el.setAttribute('src', src);
		});
		placeholders();
	}

	/**
	 * Muestra un aviso en lugar de cada contenido incrustado bloqueado.
	 */
	function placeholders() {
		$$('iframe[data-dsmkc-src]').forEach(function (f) {
			if (f._dsmkcPh) { return; }
			var svc = f.getAttribute('data-dsmkc-service');
			var cat = f.getAttribute('data-dsmkc-category');
			if (H.allowed(cat, svc)) { return; }
			var name = (C.names && C.names[svc]) || svc;
			var ph = d.createElement('div');
			var cs = w.getComputedStyle ? w.getComputedStyle(f) : null;
			ph.className = 'dsmkc-placeholder';
			ph.setAttribute('data-nosnippet', '');
			ph.innerHTML = '<p>' + esc(C.t.blocked.replace('%s', name)) + '</p>' +
				'<p><button type="button" class="dsmkc-btn dsmkc-btn--primary" data-dsmkc-grant="' + esc(svc) + '">' + esc(C.t.load) + '</button> ' +
				'<button type="button" class="dsmkc-btn dsmkc-btn--secondary" data-dsmkc-action="settings">' + esc(C.t.settings) + '</button></p>';
			var h = f.offsetHeight || parseInt(f.getAttribute('height'), 10) || 220;
			ph.style.minHeight = Math.max(160, h) + 'px';
			if (cs && cs.position === 'absolute') {
				ph.style.position = 'absolute';
				ph.style.top = '0';
				ph.style.left = '0';
				ph.style.width = '100%';
				ph.style.height = '100%';
			}
			f._dsmkcDisplay = f.style.display;
			f.style.display = 'none';
			f.parentNode.insertBefore(ph, f);
			f._dsmkcPh = ph;
		});
	}

	/**
	 * Borra las cookies propias de las categorías indicadas.
	 *
	 * @param {string[]} cats Categorías.
	 */
	function deleteCookies(cats) {
		var patterns = [];
		cats.forEach(function (k) {
			((C.del && C.del[k]) || []).forEach(function (p) {
				patterns.push(new RegExp('^' + p.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*') + '$'));
			});
		});
		var host = w.location.hostname.split('.');
		d.cookie.split(';').forEach(function (pair) {
			var name = pair.split('=')[0].trim();
			if (!name || name === C.cookie) { return; }
			var hit = patterns.some(function (re) { return re.test(name); });
			if (!hit) { return; }
			var domains = [''];
			for (var i = 0; i < host.length - 1; i++) { domains.push('; domain=.' + host.slice(i).join('.')); }
			domains.forEach(function (dm) {
				d.cookie = name + '=; Max-Age=0; path=/' + dm;
				d.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/' + dm;
			});
		});
	}

	/* ------------------------------------------------------------------
	 * Decisiones
	 * ------------------------------------------------------------------ */

	/**
	 * Aplica una decisión del usuario.
	 *
	 * @param {string}   action   accept|reject|custom|service|gpc.
	 * @param {string[]} cats     Categorías aceptadas.
	 * @param {string[]} services Servicios aceptados puntualmente.
	 */
	function decide(action, cats, services) {
		var prev = H.consent;
		var c = build(cats, services);
		var denied = CATS.filter(function (k) { return !c.c[k]; });
		var revoked = !!prev && (
			CATS.some(function (k) { return prev.c && prev.c[k] && !c.c[k]; }) ||
			(prev.s || []).some(function (s) { return c.s.indexOf(s) === -1; })
		);

		store(c);
		log(action, c);
		signal(c);
		deleteCookies(denied);

		if (revoked) {
			// Lo que ya se ejecutó no se puede detener: recargar garantiza un rechazo efectivo.
			w.location.reload();
			return;
		}
		release();
		hideBanner();
		closePanel(true);
		showRevisit();
		announce(C.t.saved);
	}

	function acceptAll() { decide('accept', CATS.slice(), []); }
	function rejectAll() { decide('reject', [], []); }

	function saveSelection() {
		var cats = $$('[data-dsmkc-toggle]', panel).filter(function (i) { return i.checked; }).map(function (i) { return i.getAttribute('data-dsmkc-toggle'); });
		var keep = (H.consent && H.consent.s) || [];
		decide('custom', cats, keep);
	}

	function grantService(svc) {
		var cur = H.consent;
		var cats = CATS.filter(function (k) { return cur && cur.c && cur.c[k]; });
		var services = ((cur && cur.s) || []).slice();
		if (services.indexOf(svc) === -1) { services.push(svc); }
		decide('service', cats, services);
	}

	/* ------------------------------------------------------------------
	 * Interfaz
	 * ------------------------------------------------------------------ */
	function announce(msg) {
		if (live) { live.textContent = ''; w.setTimeout(function () { live.textContent = msg; }, 50); }
	}

	function isModal() { return root && /dsmkc-pos-modal/.test(root.className); }

	function showBanner() {
		if (!banner) { return; }
		banner.hidden = false;
		if (isModal()) {
			overlay.hidden = false;
			var first = banner.querySelector('button');
			if (first) { first.focus(); }
		}
		if (revisit) { revisit.hidden = true; }
	}

	function hideBanner() {
		if (banner) { banner.hidden = true; }
		if (overlay && panel && panel.hidden) { overlay.hidden = true; }
	}

	function showRevisit() { if (revisit) { revisit.hidden = false; } }

	function openPanel() {
		if (!panel) { return; }
		lastFocus = d.activeElement;
		var c = H.consent;
		$$('[data-dsmkc-toggle]', panel).forEach(function (input) {
			var k = input.getAttribute('data-dsmkc-toggle');
			input.checked = !!(c && c.c && c.c[k]);
		});
		banner.hidden = true;
		if (revisit) { revisit.hidden = true; }
		overlay.hidden = false;
		panel.hidden = false;
		var focusable = panel.querySelector('.dsmkc-close');
		if (focusable) { focusable.focus(); }
	}

	/**
	 * Cierra el panel.
	 *
	 * @param {boolean} decided true si se acaba de tomar una decisión.
	 */
	function closePanel(decided) {
		if (!panel || panel.hidden) { return; }
		panel.hidden = true;
		overlay.hidden = true;
		if (!decided && !H.consent) {
			showBanner(); // Sin decisión no hay consentimiento: vuelve la 1ª capa.
		} else {
			showRevisit();
		}
		if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
	}

	function trapFocus(e) {
		if (!panel || panel.hidden || e.key !== 'Tab') { return; }
		var items = $$('button, [href], input, summary', panel).filter(function (el) { return el.offsetParent !== null; });
		if (!items.length) { return; }
		var first = items[0];
		var last = items[items.length - 1];
		if (e.shiftKey && d.activeElement === first) { e.preventDefault(); last.focus(); }
		else if (!e.shiftKey && d.activeElement === last) { e.preventDefault(); first.focus(); }
	}

	function onClick(e) {
		var t = e.target;
		var grant = closest(t, '[data-dsmkc-grant]');
		if (grant) { e.preventDefault(); grantService(grant.getAttribute('data-dsmkc-grant')); return; }

		var btn = closest(t, '[data-dsmkc-action]');
		if (btn) {
			e.preventDefault();
			switch (btn.getAttribute('data-dsmkc-action')) {
				case 'accept': acceptAll(); break;
				case 'reject': rejectAll(); break;
				case 'save': saveSelection(); break;
				case 'settings': openPanel(); break;
				case 'close': closePanel(false); break;
			}
			return;
		}
		var link = closest(t, 'a[href$="#dsmk-cookies"], a[href$="#dsmkc-ajustes"], .dsmkc-open-settings');
		if (link) { e.preventDefault(); openPanel(); }
	}

	function onKey(e) {
		if (e.key === 'Escape' && panel && !panel.hidden) { closePanel(false); }
		trapFocus(e);
	}

	/* ------------------------------------------------------------------
	 * Arranque
	 * ------------------------------------------------------------------ */
	function init() {
		root = d.getElementById('dsmkc-root');
		if (root) {
			banner = root.querySelector('.dsmkc-banner');
			panel = root.querySelector('.dsmkc-panel');
			overlay = root.querySelector('.dsmkc-overlay');
			revisit = root.querySelector('.dsmkc-revisit');
			live = d.createElement('div');
			live.className = 'dsmkc-sr';
			live.setAttribute('aria-live', 'polite');
			root.appendChild(live);
		}
		d.addEventListener('click', onClick);
		d.addEventListener('keydown', onKey);
		logPending();

		if (!H.consent && C.gpc && w.navigator.globalPrivacyControl) {
			decide('gpc', [], []);
			return;
		}
		if (H.consent) {
			showRevisit();
		} else {
			showBanner();
		}
		release();

		// Contenidos bloqueados que aparecen más tarde (sliders, pestañas, popups...).
		if (w.MutationObserver) {
			var timer = null;
			new MutationObserver(function () {
				if (timer) { return; }
				timer = w.setTimeout(function () { timer = null; placeholders(); }, 200);
			}).observe(d.body, { childList: true, subtree: true });
		}
	}

	w.DSMKCookies = {
		open: openPanel,
		consent: function () { return H.consent; },
		allowed: function (cat) { return H.allowed(cat); },
		accept: acceptAll,
		reject: rejectAll
	};

	if (d.readyState === 'loading') { d.addEventListener('DOMContentLoaded', init); } else { init(); }
}(window, document));
