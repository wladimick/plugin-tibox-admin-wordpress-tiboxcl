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
  if (window.__tbxTracking || window.TBX_ANALYTICS_ALLOWED === false || navigator.globalPrivacyControl === true) return;
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

  /* ---------- Atribución de sesión, SIN identificador persistente ---------- */
  function cleanCampaign(value, max) {
    value = String(value || '').trim();
    if (/@|%40|https?:|[\r\n]/i.test(value)) return '';
    return value.replace(/[^\p{L}\p{N} _.-]/gu, '').slice(0, max || 80);
  }
  function campaignContext() {
    var c = {};
    try {
      var q = new URLSearchParams(location.search);
      c.source = cleanCampaign(q.get('utm_source'), 80);
      c.medium = cleanCampaign(q.get('utm_medium'), 50);
      c.campaign = cleanCampaign(q.get('utm_campaign'), 100);
      var hasCampaign = c.source || c.medium || c.campaign;
      if (hasCampaign) {
        sessionStorage.setItem('tbx_campaign', JSON.stringify(c));
      } else {
        var saved = JSON.parse(sessionStorage.getItem('tbx_campaign') || '{}');
        c.source = cleanCampaign(saved.source, 80);
        c.medium = cleanCampaign(saved.medium, 50);
        c.campaign = cleanCampaign(saved.campaign, 100);
      }
      if (!c.source && document.referrer) {
        var ref = new URL(document.referrer);
        if (ref.host !== location.host && /^https?:$/.test(ref.protocol)) {
          c.source = cleanCampaign(ref.hostname, 80);
          c.medium = 'referral';
        }
      }
    } catch (_) {}
    return c;
  }
  var campaign = campaignContext();

  /* ---------- Registro propio en WordPress (Tibox Admin › Actividad del sitio) ---------- */
  var TRACK_URL = window.TBX_TRACK_URL || '/wp-json/tibox/v1/track';
  var queue = [];
  var sentCount = 0;
  function labelOf(data) {
    data = data || {};
    return String(data.cta_text || data.section_id || data.faq_question || data.error_type ||
      (data.scroll_percent ? String(data.scroll_percent) : '') || data.form_name ||
      data.form_type || data.link_text || '').slice(0, 120);
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
    var body = JSON.stringify({ path: path, campaign: campaign, events: batch });
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

  /* ---------- Archivos y enlaces externos (solo resumen local, sin GTM adicional) ---------- */
  document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('a[href]') : null;
    if (!a) return;
    var raw = a.getAttribute('href') || '';
    if (/^(mailto:|tel:|javascript:|#)/i.test(raw)) return;
    try {
      var url = new URL(raw, location.href);
      if (!/^https?:$/.test(url.protocol)) return;
      var label = clean(a.getAttribute('data-track') || a.textContent || a.getAttribute('aria-label'), 100) || 'Enlace';
      if (a.hasAttribute('download') || /\.(pdf|docx?|xlsx?|pptx?|zip|csv)$/i.test(url.pathname)) {
        log('download_click', { link_text: label, click_location: locationOf(a) });
      } else if (url.host !== location.host && !/(wa\.me|api\.whatsapp\.com|web\.whatsapp\.com)/i.test(url.host)) {
        log('outbound_click', { link_text: label, click_location: locationOf(a) });
      }
    } catch (_) {}
  }, true);

  /* ---------- Tiempo activo estimado; nunca guarda ID de usuario ---------- */
  var lastInteraction = 0;
  ['pointerdown', 'keydown', 'scroll', 'touchstart'].forEach(function (ev) {
    window.addEventListener(ev, function () { lastInteraction = Date.now(); }, { passive: true });
  });
  setInterval(function () {
    if (document.visibilityState === 'visible' && lastInteraction && Date.now() - lastInteraction < 20000) {
      log('engaged_15s', {});
    }
  }, 15000);

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
  /* ---------- Formularios: inicio genérico, éxito solo confirmado ---------- */
  function wpformsId(form) {
    return String(form.getAttribute('data-formid') || (form.id || '').replace('wpforms-form-', ''));
  }
  var FORM_SELECTOR = 'form.wpforms-form, form.elementor-form, form.wpcf7-form, form[data-tbx-track-form]';
  function formInfo(form) {
    if (!form) return { form_name: 'sin-formulario', form_type: 'otro' };
    if (form.matches('form.wpforms-form')) {
      var id = wpformsId(form);
      return { form_name: 'wpforms-' + id, form_type: WPFORMS_TYPES[id] || 'otro' };
    }
    if (form.matches('form.elementor-form')) {
      var name = form.querySelector('[name="form_id"]');
      var idEl = (name && name.value) || form.id || 'sin-id';
      return { form_name: 'elementor-' + clean(idEl, 60), form_type: 'otro' };
    }
    if (form.matches('form.wpcf7-form')) {
      var idCf7 = form.querySelector('[name="_wpcf7"]');
      return { form_name: 'cf7-' + clean(idCf7 && idCf7.value, 60), form_type: 'otro' };
    }
    return { form_name: 'html-' + clean(form.id || form.getAttribute('data-tbx-track-form') || 'formulario', 60), form_type: 'otro' };
  }
  function onFormInput(e) {
    var form = e.target.closest ? e.target.closest(FORM_SELECTOR) : null;
    if (!form || form.__tbxStarted) return;
    form.__tbxStarted = true;
    var info = formInfo(form);
    if (form.matches('form.wpforms-form')) {
      // Compatibilidad: el evento existente de WPForms sigue llegando a GTM.
      push('form_start', info);
    } else {
      log('form_start', info);
    }
  }
  document.addEventListener('input', onFormInput, true);
  document.addEventListener('change', onFormInput, true);

  function confirmed(form) {
    if (!form || !form.matches(FORM_SELECTOR)) return;
    // Ignora hooks duplicados por 2 segundos, sin bloquear envíos legítimos posteriores.
    var now = Date.now();
    if (form.__tbxSuccessAt && now - form.__tbxSuccessAt < 2000) return;
    form.__tbxSuccessAt = now;
    log('form_success', formInfo(form)); // No crea otra conversión en GTM.
  }
  function bindForms() {
    if (window.jQuery) {
      window.jQuery(document).on('wpformsAjaxSubmitSuccess', function (e) {
        var form = e.target.closest && e.target.closest('form.wpforms-form');
        if (!form) return;
        push('wpforms_submit', formInfo(form)); // Evento legacy existente.
        confirmed(form);
      });
      window.jQuery(document).on('wpformsAjaxSubmitFailed wpformsAjaxSubmitActionRequired', function (e) {
        var form = e.target.closest && e.target.closest('form.wpforms-form');
        if (form) push('form_error', Object.assign(formInfo(form), { error_type: 'envio_rechazado' }));
      });
      window.jQuery(document).on('submit_success', function (e) {
        var form = e.target.closest && e.target.closest('form.elementor-form');
        if (form) confirmed(form);
      });
    }
    document.addEventListener('wpcf7mailsent', function (e) {
      var form = e.target.querySelector && e.target.querySelector('form.wpcf7-form');
      if (form) confirmed(form);
    });
    document.addEventListener('wpcf7invalid', function (e) {
      var form = e.target.querySelector && e.target.querySelector('form.wpcf7-form');
      if (form) log('form_error', Object.assign(formInfo(form), { error_type: 'validacion' }));
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

  function ready() { watchSections(); bindForms(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready);
  else ready();
})();
