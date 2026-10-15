/*!
 * TIBOX Admin — registro individual de clics de contacto.
 * Se carga solo si está habilitado el modo nativo y TIBOX Leads no está activo.
 * No usa cookies, no genera identificadores persistentes ni eventos GTM.
 */
(function () {
  'use strict';
  if (window.__tbxNativeClicks ||
      window.TBX_ANALYTICS_ALLOWED === false ||
      navigator.globalPrivacyControl === true) return;
  window.__tbxNativeClicks = true;

  var ENDPOINT = window.TBX_CONTACT_ENDPOINT || '/wp-json/tibox/v1/contact-event';
  var last = new WeakMap();
  var alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

  function reference() {
    if (!window.crypto || !window.crypto.getRandomValues) return '';
    var random = new Uint8Array(6);
    window.crypto.getRandomValues(random);
    return Array.prototype.map.call(random, function (n) {
      return alphabet.charAt(n % alphabet.length);
    }).join('');
  }

  function kindOf(href) {
    if (/^tel:/i.test(href)) return 'phone';
    if (/^mailto:/i.test(href)) return 'email';
    try {
      var u = new URL(href, location.href);
      if (/^(wa\.me|wa\.link|api\.whatsapp\.com|web\.whatsapp\.com|chat\.whatsapp\.com)$/i.test(u.hostname)) {
        return 'whatsapp';
      }
    } catch (_) {}
    return '';
  }

  function whereIs(el) {
    if (el.closest('header,nav,.elementor-location-header')) return 'header';
    if (el.closest('footer,.elementor-location-footer')) return 'footer';
    if (el.closest('.elementor-popup-modal,[role="dialog"]')) return 'popup';
    if (el.closest('.ht-ctc-chat,.joinchat,.qlwapp,#tbx-wa')) return 'flotante';
    return 'contenido';
  }

  function addWhatsAppRef(a, ref) {
    if (!ref) return '';
    try {
      var u = new URL(a.href, location.href);
      var n = (u.searchParams.get('phone') || (/^wa\.me$/i.test(u.hostname) ? u.pathname : '')).replace(/\D/g, '');
      if (!/^[0-9]{8,15}$/.test(n)) return '';
      var message = u.searchParams.get('text') || '';
      message = message.replace(/\s*Ref: [A-Z0-9]{6}\s*$/i, '').trim();
      u.searchParams.set('text', (message ? message + '\n\n' : '') + 'Ref: ' + ref);
      a.href = u.toString();
      return n;
    } catch (_) { return ''; }
  }

  function attribution() {
    var c = {};
    try {
      var params = new URLSearchParams(location.search);
      ['source', 'medium', 'campaign'].forEach(function (key) {
        c[key] = String(params.get('utm_' + key) || '').slice(0, 100);
      });
      if (!c.source && !c.medium && !c.campaign && window.sessionStorage) {
        var saved = JSON.parse(window.sessionStorage.getItem('tbx_campaign') || '{}');
        ['source', 'medium', 'campaign'].forEach(function (key) {
          c[key] = String(saved[key] || '').slice(0, 100);
        });
      }
    } catch (_) {}
    return c;
  }

  function send(data) {
    try {
      var body = JSON.stringify(data);
      if (navigator.sendBeacon && navigator.sendBeacon(
          ENDPOINT, new Blob([body], { type: 'application/json' }))) return;
      fetch(ENDPOINT, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: body, credentials: 'omit', keepalive: true
      }).catch(function () {});
    } catch (_) {}
  }

  document.addEventListener('click', function (event) {
    var a = event.target.closest ? event.target.closest('a[href]') : null;
    if (!a) return;
    var raw = a.getAttribute('href') || '';
    var kind = kindOf(raw);
    if (!kind) return;
    var time = Date.now();
    if (time - (last.get(a) || 0) < 1300) return;
    last.set(a, time);

    var ref = '';
    var destination = '';
    if (kind === 'whatsapp') {
      var proposed = reference();
      destination = addWhatsAppRef(a, proposed);
      ref = destination ? proposed : '';
    } else if (kind === 'email') {
      destination = raw.replace(/^mailto:/i, '').split('?')[0].slice(0, 190);
    } else {
      destination = raw.replace(/^tel:/i, '').slice(0, 80);
    }

    var text = String(a.getAttribute('data-track') || a.getAttribute('aria-label') ||
      a.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 100);
    var data = {
      type: kind, ref: ref, destination: destination,
      button_text: text, location: whereIs(a),
      path: location.pathname, campaign: attribution()
    };
    send(data);
  }, true);
})();
