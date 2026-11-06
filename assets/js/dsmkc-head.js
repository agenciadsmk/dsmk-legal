/**
 * DSMK Legal — Script de arranque (se imprime en línea, lo primero del <head>).
 *
 * @package   DSMK_Legal
 * @file      assets/js/dsmkc-head.js
 * @since     1.0.0
 * @author    DSMK Agencia Digital <hola@dsmk.es>
 * @license   GPL-2.0-or-later
 *
 * Responsabilidad:
 *   1. Leer el consentimiento guardado (cookie dsmkc_consent) lo antes posible.
 *   2. Google Consent Mode v2: estado por defecto DENEGADO y actualización
 *      inmediata si el visitante ya había decidido.
 *   3. Interceptor: bloquea los <script> e <iframe> que se crean por código
 *      (document.createElement) y apuntan a un servicio no consentido
 *      (GTM, iframe_api de YouTube, píxeles cargados por otros scripts...).
 *   4. Red de seguridad: MutationObserver que neutraliza scripts de
 *      seguimiento insertados por el parser que se hayan escapado del filtro
 *      del servidor (p. ej. HTML servido desde una caché antigua).
 *
 * Expone window.dsmkcHead con: consent, allowed(), match(), queue, ce().
 *
 * Requisitos: ES5 (sin transpilar), sin dependencias. Debe ser pequeño.
 */
(function (w, d) {
	'use strict';

	var H = w.dsmkcHead = w.dsmkcHead || {};
	var rules = H.rules || [];
	H.queue = [];

	/**
	 * Lee y valida el consentimiento guardado.
	 * Inválido si cambia la versión de la política o ha caducado.
	 *
	 * @return {Object|null}
	 */
	function readConsent() {
		try {
			var m = d.cookie.match(new RegExp('(?:^|; )' + H.cookie + '=([^;]*)'));
			if (!m) { return null; }
			var c = JSON.parse(decodeURIComponent(m[1]));
			if (!c || c.v !== H.v || !c.t || (new Date().getTime() - c.t) > H.days * 864e5) { return null; }
			c.c = c.c || {};
			c.s = c.s || [];
			return c;
		} catch (e) {
			return null;
		}
	}

	H.consent = readConsent();

	/**
	 * ¿Está permitida una categoría o un servicio concreto?
	 *
	 * @param {string} cat Categoría.
	 * @param {string} svc Id de servicio (consentimiento puntual desde un contenido bloqueado).
	 * @return {boolean}
	 */
	H.allowed = function (cat, svc) {
		var c = H.consent;
		if (cat === 'necessary') { return true; }
		if (!c) { return false; }
		if (c.c && c.c[cat]) { return true; }
		return !!(svc && c.s && c.s.indexOf(svc) !== -1);
	};

	/**
	 * Busca la regla de bloqueo que corresponde a una URL.
	 *
	 * @param {string} url URL.
	 * @return {{s:string,c:string}|null}
	 */
	H.match = function (url) {
		if (!url) { return null; }
		url = String(url).toLowerCase();
		for (var i = 0; i < rules.length; i++) {
			for (var j = 0; j < rules[i].p.length; j++) {
				if (url.indexOf(String(rules[i].p[j]).toLowerCase()) !== -1) { return rules[i]; }
			}
		}
		return null;
	};

	/* ------------------------------------------------------------------
	 * Google Consent Mode v2
	 * ------------------------------------------------------------------ */
	w.dataLayer = w.dataLayer || [];
	function gtag() { w.dataLayer.push(arguments); }
	if (typeof w.gtag !== 'function') { w.gtag = gtag; }

	/**
	 * Estado de Consent Mode a partir de un consentimiento.
	 *
	 * @param {Object|null} c Consentimiento.
	 * @return {Object}
	 */
	H.cmState = function (c) {
		var a = (c && c.c) || {};
		var g = function (on) { return on ? 'granted' : 'denied'; };
		return {
			ad_storage: g(a.marketing),
			ad_user_data: g(a.marketing),
			ad_personalization: g(a.marketing),
			analytics_storage: g(a.analytics),
			personalization_storage: g(a.preferences),
			functionality_storage: 'granted',
			security_storage: 'granted'
		};
	};

	if (H.cm && H.cm.on) {
		var def = H.cmState(null);
		def.wait_for_update = 500;
		gtag('consent', 'default', def);
		if (H.cm.redact) { gtag('set', 'ads_data_redaction', true); }
		if (H.cm.passthru) { gtag('set', 'url_passthrough', true); }
		if (H.consent) { gtag('consent', 'update', H.cmState(H.consent)); }
	}

	/* ------------------------------------------------------------------
	 * Interceptor de elementos creados por código
	 * ------------------------------------------------------------------ */
	var nativeCreate = d.createElement;

	/** createElement original (sin interceptor), para reactivar scripts. */
	H.ce = function (tag) { return nativeCreate.call(d, tag); };

	var descriptors = {};
	try {
		descriptors.script = Object.getOwnPropertyDescriptor(HTMLScriptElement.prototype, 'src');
		descriptors.iframe = Object.getOwnPropertyDescriptor(HTMLIFrameElement.prototype, 'src');
	} catch (e) { /* Navegador antiguo: queda el filtro del servidor. */ }

	/**
	 * Vigila la propiedad src y setAttribute('src') de un elemento nuevo.
	 *
	 * @param {HTMLElement} el  Elemento.
	 * @param {string}      tag script|iframe.
	 */
	function guard(el, tag) {
		var D = descriptors[tag];
		if (!D || !D.set) { return; }
		var nativeSetAttr = el.setAttribute;
		try {
			Object.defineProperty(el, 'src', {
				configurable: true,
				enumerable: true,
				get: function () { return D.get.call(el); },
				set: function (value) {
					var r = H.match(value);
					if (r && !H.allowed(r.c, r.s)) {
						nativeSetAttr.call(el, 'data-dsmkc-src', value);
						nativeSetAttr.call(el, 'data-dsmkc-category', r.c);
						nativeSetAttr.call(el, 'data-dsmkc-service', r.s);
						if (tag === 'script') {
							nativeSetAttr.call(el, 'type', 'text/plain');
							H.queue.push(el);
						} else {
							nativeSetAttr.call(el, 'class', ((el.getAttribute('class') || '') + ' dsmkc-blocked').trim());
							D.set.call(el, 'about:blank');
						}
						return;
					}
					D.set.call(el, value);
				}
			});
			el.setAttribute = function (name, value) {
				if (String(name).toLowerCase() === 'src') { el.src = value; return; }
				if (tag === 'script' && String(name).toLowerCase() === 'type' && el.hasAttribute('data-dsmkc-src')) { return; }
				return nativeSetAttr.apply(el, arguments);
			};
		} catch (e) { /* Si falla, el elemento sigue su curso normal. */ }
	}

	d.createElement = function (tagName) {
		var el = nativeCreate.apply(d, arguments);
		var t = String(tagName).toLowerCase();
		if (t === 'script' || t === 'iframe') { guard(el, t); }
		return el;
	};

	/* ------------------------------------------------------------------
	 * Red de seguridad para scripts insertados por el parser
	 * ------------------------------------------------------------------ */
	if (w.MutationObserver) {
		var mo = new MutationObserver(function (mutations) {
			for (var i = 0; i < mutations.length; i++) {
				var nodes = mutations[i].addedNodes;
				for (var j = 0; j < nodes.length; j++) {
					var n = nodes[j];
					if (n.nodeType !== 1 || n.tagName !== 'SCRIPT' || !n.src || n.hasAttribute('data-dsmkc-ignore')) { continue; }
					var t = (n.getAttribute('type') || '').toLowerCase();
					if (t === 'text/plain') { continue; }
					var r = H.match(n.src);
					if (r && !H.allowed(r.c, r.s)) {
						n.setAttribute('data-dsmkc-src', n.src);
						n.setAttribute('data-dsmkc-category', r.c);
						n.setAttribute('data-dsmkc-service', r.s);
						n.type = 'text/plain';
						n.addEventListener('beforescriptexecute', function stop(e) {
							if (this.getAttribute('type') === 'text/plain') { e.preventDefault(); }
							this.removeEventListener('beforescriptexecute', stop);
						});
						H.queue.push(n);
					}
				}
			}
		});
		mo.observe(d.documentElement, { childList: true, subtree: true });
		w.addEventListener('load', function () { mo.disconnect(); });
	}
}(window, document));
