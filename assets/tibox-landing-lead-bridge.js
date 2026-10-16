/*!
 * TIBOX Admin — medición de éxito del endpoint propio de landings.
 * Componente opt-in, sin PII, para /wp-json/tibox/v1/lead.
 * Requiere respuesta success=true, lead_created=true y lead_id válido.
 * No transforma las respuestas ni sustituye las mediciones existentes.
 */
(function () {
  'use strict';
  if (window.__tbxLandingLeadBridge || window.TBX_ANALYTICS_ALLOWED === false ||
      navigator.globalPrivacyControl === true) return;
  var config = window.TBX_LANDING_GTM_COMPAT || {};
  if (config.enabled !== true || typeof window.fetch !== 'function') return;
  window.__tbxLandingLeadBridge = true;

  var originalFetch = window.fetch;
  var seenLeadIds = new Set();

  function consented() {
    return window.TBX_ANALYTICS_ALLOWED !== false &&
      navigator.globalPrivacyControl !== true &&
      !window.__tbxLeads;
  }
  function isLeadEndpoint(input) {
    var url = typeof input === 'string' ? input : (input && input.url) || '';
    try {
      var target = new URL(url, window.location.href);
      return target.origin === window.location.origin &&
        target.pathname.replace(/\/+$/, '') === '/wp-json/tibox/v1/lead';
    } catch (_) { return false; }
  }
  function formIdOf(options) {
    var body = options && options.body;
    try {
      if (typeof body === 'string') {
        var data = body.trim().charAt(0) === '{' ?
          JSON.parse(body) : new URLSearchParams(body);
        var id = data && (typeof data.get === 'function' ? data.get('form_id') : data.form_id);
        return String(id || '').replace(/[^a-zA-Z0-9_-]/g, '').slice(0, 100);
      }
      if (body && typeof body.get === 'function') {
        return String(body.get('form_id') || '')
          .replace(/[^a-zA-Z0-9_-]/g, '').slice(0, 100);
      }
    } catch (_) {}
    return '';
  }
  function record(body, requestId) {
    if (!consented() || !body || body.success !== true ||
        body.lead_created !== true) return;
    var id = Number(body.lead_id);
    if (!Number.isSafeInteger(id) || id < 1) return;
    // La PK guardada en BD es la fuente de verdad: no contar el mismo lead dos veces.
    if (seenLeadIds.has(id)) return;
    seenLeadIds.add(id);
    if (seenLeadIds.size > 200) seenLeadIds.clear();
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({
      event: 'tbx_generate_lead',
      form_id: requestId || 'tibox-lead-form',
      form_name: 'Landing TIBOX',
      origen_formulario: 'html'
    });
  }

  window.fetch = function () {
    var input = arguments[0], options = arguments[1];
    var method = String((options && options.method) ||
      (input && typeof input === 'object' && input.method) || 'GET').toUpperCase();
    var candidate = method === 'POST' && isLeadEndpoint(input);
    var requestId = candidate ? formIdOf(options) : '';
    // Mantener exactamente el Promise original (y sus errores) del sitio.
    var responsePromise = originalFetch.apply(this, arguments);
    if (candidate) {
      Promise.resolve(responsePromise).then(function (response) {
        if (!response || response.ok !== true || typeof response.clone !== 'function') return;
        // No consumir el body que la landing necesita para su flujo propio.
        response.clone().json().then(function (body) {
          record(body, requestId);
        }).catch(function () {});
      }).catch(function () {});
    }
    return responsePromise;
  };
})();
