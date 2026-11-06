/**
 * DSMK Legal — Scripts del panel de administración.
 *
 * @package   DSMK_Legal
 * @file      assets/js/dsmkc-admin.js
 * @since     1.0.0
 * @author    DSMK Agencia Digital <hola@dsmk.es>
 * @license   GPL-2.0-or-later
 *
 * Responsabilidad:
 *   - Selectores de color y vista previa en vivo del banner.
 *   - Mostrar / ocultar los campos de color según el modo de estilo.
 *   - Escaneo en el navegador: carga las páginas de la web en iframes ocultos
 *     con ?dsmkc_scan=TOKEN (sin bloqueos), recoge cookies propias, claves de
 *     localStorage y recursos cargados, limpia las cookies creadas durante el
 *     escaneo y envía el resultado por AJAX (con nonce).
 *   - Botón «Copiar» de la declaración de cumplimiento.
 *   - Auditoría completa: escaneo en navegador y, al terminar, auditoría de servidor.
 */
/* global jQuery, dsmkcAdmin */
(function ($) {
	'use strict';

	var A = window.dsmkcAdmin || {};

	/* -------------------------------------------------- Estilo y vista previa */
	var varMap = {
		color_fondo: '--dsmkc-bg',
		color_texto: '--dsmkc-text',
		color_primario: '--dsmkc-primary',
		color_texto_primario: '--dsmkc-primary-text',
		color_enlace: '--dsmkc-link'
	};

	function previewRoot() { return document.querySelector('.dsmkc-preview .dsmkc-root'); }

	function applyVar(field, value) {
		var root = previewRoot();
		if (!root) { return; }
		var mode = $('#dsmkc-estilo_modo').val();
		if (varMap[field] && mode === 'personalizado') { root.style.setProperty(varMap[field], value); }
		if (field === 'radio') { root.style.setProperty('--dsmkc-radius', value + 'px'); }
		if (field === 'tamano_fuente') { root.style.setProperty('--dsmkc-size', value + 'px'); }
		if (field === 'ancho_max') { root.style.setProperty('--dsmkc-maxw', value + 'px'); }
	}

	function toggleStyleFields() {
		var custom = $('#dsmkc-estilo_modo').val() === 'personalizado';
		$('[data-section="estilo"] .dsmkc-field--color').toggle(custom);
		var root = previewRoot();
		if (root && !custom) {
			Object.keys(varMap).forEach(function (k) { root.style.removeProperty(varMap[k]); });
		}
		if (root && custom) {
			Object.keys(varMap).forEach(function (k) { applyVar(k, $('#dsmkc-' + k).val()); });
		}
	}

	$(function () {
		$('.dsmkc-color').wpColorPicker({
			change: function (e, ui) { applyVar($(e.target).data('var'), ui.color.toString()); },
			clear: function (e) { applyVar($(e.target).closest('.wp-picker-container').find('.dsmkc-color').data('var'), ''); }
		});
		$('#dsmkc-radio, #dsmkc-tamano_fuente, #dsmkc-ancho_max').on('input change', function () {
			applyVar(this.id.replace('dsmkc-', ''), this.value);
		});
		$('#dsmkc-estilo_modo').on('change', toggleStyleFields);
		if ($('#dsmkc-estilo_modo').length) { toggleStyleFields(); }

		$('#dsmkc-banner_posicion').on('change', function () {
			var root = previewRoot();
			if (root) { root.className = root.className.replace(/dsmkc-pos-\S+/, 'dsmkc-pos-' + this.value); }
		});

		$('#dsmkc-preview-panel').on('click', function () {
			var panel = document.querySelector('.dsmkc-preview .dsmkc-panel');
			if (panel) { panel.hidden = !panel.hidden; }
		});

		// Los botones de la vista previa no hacen nada.
		$(document).on('click', '.dsmkc-preview [data-dsmkc-action]', function (e) { e.preventDefault(); });

		$('[data-dsmkc-copy]').on('click', function () {
			var el = document.querySelector($(this).data('dsmkc-copy'));
			var btn = $(this);
			if (!el) { return; }
			el.select();
			(navigator.clipboard ? navigator.clipboard.writeText(el.value) : Promise.resolve(document.execCommand('copy'))).then(function () {
				btn.text(A.t.copied);
			});
		});

		$('#dsmkc-browser-scan').on('click', function () { browserScan.call(this, null); });
		$('#dsmkc-audit').on('click', function () {
			var href = $(this).data('href');
			browserScan.call(this, function () { window.location.href = href; });
		});
	});

	/* -------------------------------------------------- Escaneo en navegador */
	function cookieNames() {
		return document.cookie.split(';').map(function (p) { return p.split('=')[0].trim(); }).filter(Boolean);
	}

	function expire(name) {
		var host = location.hostname.split('.');
		var domains = [''];
		for (var i = 0; i < host.length - 1; i++) { domains.push('; domain=.' + host.slice(i).join('.')); }
		domains.forEach(function (d) { document.cookie = name + '=; Max-Age=0; path=/' + d; });
	}

	function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

	/**
	 * Carga una URL en un iframe oculto y recoge lo que ha instalado.
	 *
	 * @param {string} url URL de la web.
	 * @return {Promise<Object|null>}
	 */
	function inspect(url) {
		return new Promise(function (resolve) {
			var frame = document.createElement('iframe');
			var sep = url.indexOf('?') === -1 ? '?' : '&';
			var done = false;
			frame.setAttribute('aria-hidden', 'true');
			frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:1280px;height:900px;border:0;visibility:hidden';
			frame.src = url + sep + 'dsmkc_scan=' + encodeURIComponent(A.token);

			var finish = function () {
				if (done) { return; }
				done = true;
				var out = null;
				try {
					var w = frame.contentWindow;
					var d = w.document;
					var storage = [];
					try { for (var i = 0; i < w.localStorage.length; i++) { storage.push(w.localStorage.key(i)); } } catch (e) {}
					var resources = [];
					try { resources = w.performance.getEntriesByType('resource').map(function (r) { return r.name; }); } catch (e) {}
					Array.prototype.forEach.call(d.querySelectorAll('script[src], iframe[src], img[src]'), function (el) { resources.push(el.src); });
					out = {
						cookies: d.cookie.split(';').map(function (p) { return p.split('=')[0].trim(); }).filter(Boolean),
						storage: storage,
						resources: resources
					};
				} catch (e) {
					out = null;
				}
				frame.parentNode.removeChild(frame);
				resolve(out);
			};

			frame.onload = function () { wait(7000).then(finish); };
			setTimeout(finish, 25000);
			document.body.appendChild(frame);
		});
	}

	/**
	 * Escaneo en el navegador.
	 *
	 * @param {Function|null} next Qué hacer al terminar (auditoría completa); null = recargar.
	 */
	function browserScan(next) {
		var btn = $(this);
		var urls = A.urls || [];
		var bar = $('#dsmkc-scan-progress');
		var before = cookieNames();
		var result = { cookies: [], storage: [], resources: [], pages: 0 };
		var failed = 0;

		btn.prop('disabled', true);
		bar.prop('hidden', false);

		var chain = Promise.resolve();
		urls.forEach(function (url, i) {
			chain = chain.then(function () {
				bar.find('.dsmkc-progress-text').text(A.t.scanning + ' ' + (i + 1) + '/' + urls.length + ': ' + url);
				bar.find('.dsmkc-progress-bar').css('width', Math.round((i / urls.length) * 100) + '%');
				return inspect(url).then(function (r) {
					if (!r) { failed++; return; }
					result.pages++;
					result.cookies = result.cookies.concat(r.cookies);
					result.storage = result.storage.concat(r.storage);
					result.resources = result.resources.concat(r.resources);
				});
			});
		});

		chain.then(function () {
			// Limpia las cookies que se han creado durante el escaneo en el navegador del administrador.
			cookieNames().forEach(function (n) { if (before.indexOf(n) === -1 && n !== 'dsmkc_consent') { expire(n); } });
			bar.find('.dsmkc-progress-bar').css('width', '100%');
			if (!result.pages) {
				bar.find('.dsmkc-progress-text').text(A.t.error);
				if (next) { next(); return; } // La auditoría de servidor sigue adelante.
				btn.prop('disabled', false);
				return;
			}
			return $.post(A.ajax, { action: 'dsmkc_browser_scan', nonce: A.nonce, payload: JSON.stringify(result) }).then(function () {
				bar.find('.dsmkc-progress-text').text(next ? A.t.auditing : A.t.done);
				if (next) { next(); return; }
				window.location.href = window.location.href.replace(/&dsmkc_msg=[^&]*/g, '') + '&dsmkc_msg=scanned';
			}, function () {
				bar.find('.dsmkc-progress-text').text(A.t.error);
				if (next) { next(); return; }
				btn.prop('disabled', false);
			});
		});
	}
}(jQuery));
