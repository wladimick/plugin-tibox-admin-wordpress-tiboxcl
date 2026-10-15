/*!
 * TIBOX — Medición de interacciones (sitio + landings) · v1.1 · 15-10-2026
 * Envía eventos al dataLayer de GTM. No envía datos personales.
 *
 * Eventos:
 *   phone_click      clic en enlaces tel:
 *   email_click      clic en enlaces mailto:
 *   whatsapp_click   clic en wa.me / api.whatsapp.com
 *   portal_click     clic en enlaces al portal de clientes (soporte.tibox.cl)
 *   portal_open      apertura del aviso «¿Eres cliente?» del menú
 *   cta_click        clic en botones de llamado a la acción
 *   form_start       primer campo completado en un formulario WPForms
 *   wpforms_submit   envío exitoso de un formulario WPForms (con form_type)
 *   form_error       envío rechazado (WPForms o landing)
 *   faq_open         apertura de una pregunta frecuente
 *   section_view     una sección de la landing quedó visible (una vez)
 *   scroll_depth     25 / 50 / 75 / 90 % de la página
 *
 * Se puede cargar dos veces sin duplicar eventos.
 * Además guarda un resumen en WordPress (plugin Tibox Admin › Actividad del sitio).
 */
(function () {
  'use strict';
  if (window.__tbxTracking) return;
  window.__tbxTracking = true;

  var dl = (window.dataLayer = window.dataLayer || []);
  var path = window.location.pathname;
  var isLanding = /^\/lp\//.test(path);

  /* Tipos de formularios WPForms (ID → tipo de conversión) */
  var WPFORMS_TYPES = {
    '830': 'lead', '10646': 'lead', '16769': 'lead', '17697': 'lead', '17672': 'lead',
    '11571': 'evento', '19589': 'evento', '19597': 'evento',
    '9918': 'postulacion', '13156': 'postulacion'
  };

  /* ---------- Registro propio en WordPress (Tibox Admin › Actividad del sitio) ---------- */
  var TRACK_URL = window.TBX_TRACK_URL || '/wp-json/tibox/v1/track';
  var queue = [];
  var sentCount = 0;
  function labelOf(data) {
    data = data || {};
    return String(data.cta_text || data.section_id || data.faq_question || data.error_type ||
      (data.scroll_percent ? String(data.scroll_percent) : '') || data.form_type || data.link_text || '').slice(0, 120);
  }
  function log(event, data) {
    if (sentCount + queue.length >= 80) return;
    queue.push({ e: event, l: (data && data.click_location) || '', t: labelOf(data) });
    if (queue.length >= 15) flush();
  }
  function flush() {
    if (!queue.length) return;
    var batch = queue.splice(0, 40);
    sentCount += batch.length;
    var body = JSON.stringify({ path: path, events: batch });
    try {
      if (navigator.sendBeacon) {
        navigator.sendBeacon(TRACK_URL, new Blob([body], { type: 'application/json' }));
      } else {
        fetch(TRACK_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body, keepalive: true });
      }
    } catch (_) {}
  }
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') flush(); });
  window.addEventListener('pagehide', flush);
  setInterval(flush, 15000);
  /* Para que la landing registre form_start / form_submit / form_error */
  window.tbxLog = log;
  log('page_view', {});

  function push(event, data) {
    var payload = { event: event, landing_path: path, page_type: isLanding ? 'landing' : 'sitio' };
    for (var k in data) {
      if (Object.prototype.hasOwnProperty.call(data, k) && data[k] !== '' && data[k] != null) {
        payload[k] = data[k];
      }
    }
    dl.push(payload);
    log(event, data);
  }

  function clean(text, max) {
    return String(text || '').replace(/\s+/g, ' ').trim().slice(0, max || 100);
  }

  /* Dónde está el elemento: header, footer o la sección con id más cercana */
  function locationOf(el) {
    if (el.closest('header, .hdr, #masthead, .site-header')) return 'header';
    if (el.closest('footer, .ftr, .site-footer')) return 'footer';
    if (el.closest('.qn, .qn-bar')) return 'accesos_rapidos';
    var sec = el.closest('section[id], [data-track-section]');
    if (sec) return sec.getAttribute('data-track-section') || sec.id;
    if (el.closest('#tibox-mega-menu, .canvas-content, nav')) return 'menu';
    return 'contenido';
  }

  /* ---------- Clics ---------- */
  var CTA_SELECTOR = '[data-track], .js-go-form, .btn-cta, .btn-ghost, .elementor-button, .wp-block-button__link, .tbx-btn-primary';

  document.addEventListener('click', function (e) {
    var el = e.target.closest ? e.target.closest('a, button') : null;
    if (!el) return;

    var href = el.getAttribute('href') || '';
    var text = clean(el.getAttribute('data-track') || el.textContent || el.getAttribute('aria-label'));
    var where = locationOf(el);

    if (/^tel:/i.test(href)) {
      push('phone_click', { link_url: href.replace(/^tel:/i, ''), link_text: text, click_location: where });
      return;
    }
    if (/^mailto:/i.test(href)) {
      push('email_click', { link_url: href.replace(/^mailto:/i, '').split('?')[0], link_text: text, click_location: where });
      return;
    }
    if (/(wa\.me|api\.whatsapp\.com|web\.whatsapp\.com)/i.test(href)) {
      push('whatsapp_click', { link_url: href, link_text: text, click_location: where });
      return;
    }
    if (/soporte\.tibox\.cl/i.test(href)) {
      push('portal_click', { link_url: href, link_text: text, click_location: where });
      return;
    }
    if (/^Ingreso_portal_clientes/.test(el.id || '')) {
      push('portal_open', { link_text: text, click_location: where });
      return;
    }
    if (el.type === 'submit') {
      return; /* los envíos se miden al confirmarse, no al hacer clic */
    }
    if (el.matches(CTA_SELECTOR)) {
      push('cta_click', { cta_text: text, link_url: href, click_location: where });
    }
  }, true);

  /* ---------- Preguntas frecuentes (<details> y acordeones Elementor) ---------- */
  /* Solo cuenta aperturas hechas por la persona (no las que vienen abiertas al cargar) */
  var interacted = false;
  ['pointerdown', 'keydown'].forEach(function (t) {
    document.addEventListener(t, function () { interacted = true; }, { capture: true, passive: true });
  });
  document.addEventListener('toggle', function (e) {
    var d = e.target;
    if (interacted && d && d.tagName === 'DETAILS' && d.open) {
      var s = d.querySelector('summary');
      push('faq_open', { faq_question: clean(s ? s.textContent : '', 120) });
    }
  }, true);
  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('.elementor-tab-title, .e-n-accordion-item-title') : null;
    if (t && t.getAttribute('aria-expanded') !== 'true') {
      push('faq_open', { faq_question: clean(t.textContent, 120) });
    }
  }, true);

  /* ---------- Scroll 25 / 50 / 75 / 90 ---------- */
  var marks = [25, 50, 75, 90];
  var sent = {};
  var ticking = false;
  function checkScroll() {
    ticking = false;
    var doc = document.documentElement;
    var max = doc.scrollHeight - window.innerHeight;
    if (max <= 0) return;
    var pct = (window.pageYOffset / max) * 100;
    for (var i = 0; i < marks.length; i++) {
      if (pct >= marks[i] && !sent[marks[i]]) {
        sent[marks[i]] = true;
        push('scroll_depth', { scroll_percent: marks[i] });
      }
    }
  }
  window.addEventListener('scroll', function () {
    if (!ticking) { ticking = true; window.requestAnimationFrame(checkScroll); }
  }, { passive: true });

  /* ---------- Secciones vistas (solo landings) ---------- */
  function watchSections() {
    if (!isLanding || !('IntersectionObserver' in window)) return;
    var seen = {};
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        var id = en.target.id;
        if (en.isIntersecting && !seen[id]) {
          seen[id] = true;
          push('section_view', { section_id: id });
          io.unobserve(en.target);
        }
      });
    }, { threshold: 0.4 });
    document.querySelectorAll('section[id]').forEach(function (s) { io.observe(s); });
  }

  /* ---------- WPForms ---------- */
  function wpformsId(form) {
    return String(form.getAttribute('data-formid') || (form.id || '').replace('wpforms-form-', ''));
  }
  var started = {};
  function onFormInput(e) {
    var form = e.target.closest ? e.target.closest('form.wpforms-form') : null;
    if (!form) return;
    var id = wpformsId(form);
    if (started[id]) return;
    started[id] = true;
    push('form_start', { form_name: 'wpforms-' + id, form_type: WPFORMS_TYPES[id] || 'otro' });
  }
  document.addEventListener('input', onFormInput, true);
  document.addEventListener('change', onFormInput, true);

  function bindWpforms() {
    if (!window.jQuery) return;
    window.jQuery(document).on('wpformsAjaxSubmitSuccess', function (e) {
      var id = wpformsId(e.target);
      push('wpforms_submit', { form_name: 'wpforms-' + id, form_type: WPFORMS_TYPES[id] || 'otro' });
    });
    window.jQuery(document).on('wpformsAjaxSubmitFailed wpformsAjaxSubmitActionRequired', function (e) {
      var id = wpformsId(e.target);
      push('form_error', { form_name: 'wpforms-' + id, form_type: WPFORMS_TYPES[id] || 'otro', error_type: 'envio_rechazado' });
    });
  }

  /* Errores de validación del navegador (campos obligatorios vacíos) */
  document.addEventListener('invalid', function (e) {
    var f = e.target.form;
    if (!f || f.__tbxInvalid) return;
    f.__tbxInvalid = true;
    setTimeout(function () { f.__tbxInvalid = false; }, 1500);
    push('form_error', { form_name: f.id || 'formulario', error_type: 'campo_' + (e.target.name || 'sin_nombre') });
  }, true);

  function ready() { watchSections(); bindWpforms(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready);
  else ready();
})();
