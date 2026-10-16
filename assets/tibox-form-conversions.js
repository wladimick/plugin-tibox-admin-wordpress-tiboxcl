/*!
 * TIBOX Admin — compatibilidad opcional con eventos GTM de TIBOX Leads 1.0.0.
 *
 * SOLO tras desactivar el plugin anterior y habilitar explícitamente el ajuste.
 * Un evento es un envío confirmado en el navegador, no un simple submit.
 * No se envía PII, no se añade evento generate_lead de GA4 ni se altera GTM.
 */
(function () {
  'use strict';
  if (window.__tbxLegacyFormConversions ||
      window.__tbxLeads ||
      window.TBX_ANALYTICS_ALLOWED === false ||
      navigator.globalPrivacyControl === true) return;
  window.__tbxLegacyFormConversions = true;

  var OPTIONS = window.TBX_FORM_GTM_COMPAT || {};
  if (OPTIONS.enabled !== true) return;

  var seen = new WeakMap();
  var lastEvent = Object.create(null);

  function normalized(v, max) {
    return String(v == null ? '' : v).replace(/[\r\n\t]/g, ' ').trim().slice(0, max || 100);
  }
  function findForm(target, selector) {
    if (!target) return null;
    // jQuery puede entregar elementos como colecciones (por ejemplo,
    // el argumento `form` de WPForms). No inspeccionar otras propiedades.
    if (target.jquery && target[0]) target = target[0];
    if (target.matches && target.matches(selector)) return target;
    return target.closest ? target.closest(selector) : null;
  }
  function resolveForm(selector, candidates) {
    for (var i = 0; i < candidates.length; i++) {
      var item = candidates[i];
      if (!item) continue;
      var found = findForm(item, selector);
      if (found) return found;
      // Algunas variantes de WPForms entregan una referencia en response.form.
      if (item.form) {
        found = findForm(item.form, selector);
        if (found) return found;
      }
    }
    return null;
  }
  function consented() {
    return window.TBX_ANALYTICS_ALLOWED !== false &&
      navigator.globalPrivacyControl !== true && !window.__tbxLeads;
  }
  function push(origin, id, name, form) {
    if (!consented()) return false;
    origin = normalized(origin, 24).toLowerCase();
    if (!['wpforms','elementor','cf7','html'].includes(origin)) return false;
    id = normalized(id, 100);
    name = normalized(name, 150);
    var now = Date.now();
    var key = origin + '|' + id + '|' + name;
    if (origin === 'html') {
      // Puente REST + integración HTML explícita pueden confirmar el MISMO
      // lead. Compartir deduplicación sin bloquear otros tipos de formulario.
      var recent = window.__tbxRecentHtmlGtm || (window.__tbxRecentHtmlGtm = Object.create(null));
      if (now - (recent[id] || 0) < 2000) return false;
      recent[id] = now;
    }
    if (form) {
      // Formulario concreto: no bloquear otro formulario con mismo ID/nombre.
      // Algunos plugins disparan dos callbacks para el mismo envío AJAX.
      // Misma ventana de deduplicación que el evento wpforms_submit existente.
      if (now - (seen.get(form) || 0) < 2000) return false;
      seen.set(form, now);
    } else {
      // La confirmación no AJAX solo entrega el ID por cookie efímera.
      if (now - (lastEvent[key] || 0) < 2000) return false;
      lastEvent[key] = now;
    }
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({
      event: 'tbx_generate_lead',
      form_id: id,
      form_name: name,
      origen_formulario: origin
    });
    return true;
  }

  function wpformsMeta(form) {
    return {
      id: form ? normalized(form.getAttribute('data-formid') ||
        (form.id || '').replace(/^wpforms-form-/, ''), 100) : '',
      name: form ? normalized(form.getAttribute('aria-label') ||
        form.getAttribute('data-formname') || '', 150) : ''
    };
  }
  function elementorMeta(form) {
    var idInput = form && form.querySelector ?
      form.querySelector('input[name="form_id"]') : null;
    var nameInput = form && form.querySelector ?
      form.querySelector('input[name="form_name"]') : null;
    return {
      id: normalized((idInput && idInput.value) ||
        (form && form.getAttribute('name')) || '', 100),
      name: normalized((nameInput && nameInput.value) ||
        (form && form.getAttribute('name')) || '', 150)
    };
  }
  function cf7Meta(event) {
    var detail = event.detail || {};
    var form = findForm(event.target, 'form.wpcf7-form');
    if (!form && event.target && event.target.querySelector) {
      form = event.target.querySelector('form.wpcf7-form');
    }
    return {
      form: form,
      id: normalized(detail.contactFormId ||
        (form && form.querySelector && form.querySelector('input[name="_wpcf7"]') || {}).value || '', 100),
      name: 'Contact Form 7 #' + normalized(detail.contactFormId || '', 100)
    };
  }

  // WPForms AJAX: evento emitido por WPForms después de respuesta exitosa.
  // Elementor: evento tras envío confirmado de Elementor Pro.
  function bindJquery() {
    if (!window.jQuery) return;
    window.jQuery(document).on('wpformsAjaxSubmitSuccess', function (event, response, formArg) {
      var form = resolveForm('form.wpforms-form',
        [event && event.target, formArg, response, event && event.originalEvent && event.originalEvent.target]);
      if (!form) return; // Nunca atribuir a un formulario equivocado.
      var m = wpformsMeta(form);
      push('wpforms', m.id, m.name, form);
    });
    window.jQuery(document).on('submit_success', '.elementor-form', function (event) {
      var form = resolveForm('form.elementor-form',
        [event && event.target, this, event && event.originalEvent && event.originalEvent.target]);
      if (!form) return;
      var m = elementorMeta(form);
      push('elementor', m.id, m.name, form);
    });
  }
  if (window.jQuery) {
    bindJquery();
  } else {
    document.addEventListener('DOMContentLoaded', bindJquery, {once: true});
  }

  // CF7 confirma envío a través de su evento oficial en el navegador.
  document.addEventListener('wpcf7mailsent', function (event) {
    var m = cf7Meta(event);
    push('cf7', m.id, m.name, m.form);
  });

  // Para landings HTML propias solo se acepta un evento explícito de éxito;
  // NO escuchamos "submit" porque eso produciría conversiones falsas.
  document.addEventListener('tbx:form-success', function (event) {
    var detail = event.detail || {};
    var form = detail.form || findForm(event.target, 'form[data-tbx-track-form]');
    if (!form || !form.matches || !form.matches('form[data-tbx-track-form]')) return;
    push('html', detail.formId || form.id || form.getAttribute('data-tbx-track-form'),
      detail.formName || form.getAttribute('data-tbx-name') || '', form);
  });

  // WPForms sin AJAX: PHP deja una cookie efímera (solo ID del formulario)
  // DESPUÉS de guardar exitosamente en Contactos. Consumir una vez y borrar.
  function nonAjaxConfirmation() {
    var match = document.cookie.match(/(?:^|; )tbx_admin_form_gtm=([^;]*)/);
    if (!match) return;
    document.cookie = 'tbx_admin_form_gtm=;path=/;max-age=0;SameSite=Lax';
    var id = '';
    try { id = decodeURIComponent(match[1]); } catch (_) { return; }
    if (!/^[-_a-zA-Z0-9]{1,100}$/.test(id)) return;
    push('wpforms', id, '', null);
  }
  nonAjaxConfirmation();
})();
