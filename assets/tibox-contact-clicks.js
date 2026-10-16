/*!
 * TIBOX Admin — seguimiento nativo de WhatsApp, teléfono y correo.
 * Independiente de la captura de formularios; solo se encola cuando se habilita
 * explícitamente y TIBOX Leads está desactivado. Sin cookies ni eventos GTM.
 */
(function () {
  'use strict';
  if (window.__tbxNativeClicks || window.TBX_ANALYTICS_ALLOWED === false ||
      navigator.globalPrivacyControl === true) return;
  window.__tbxNativeClicks = true;

  var ENDPOINT = window.TBX_CONTACT_ENDPOINT || '/wp-json/tibox/v1/contact-event';
  var CONFIG = window.TBX_CONTACT_LINK_CONFIG || {};
  var ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  var WA_WIDGETS = '.ht-ctc-chat,.ht_ctc_chat_style,.joinchat__button,.qlwapp__button,.wa__btn_popup,.nta-wa-gdpr,[data-tbx-wa]';
  var last = new WeakMap();

  function digits(v) { return String(v || '').replace(/\D/g, ''); }
  function randomCode() {
    if (!window.crypto || !window.crypto.getRandomValues) return '';
    var bytes = new Uint8Array(6);
    window.crypto.getRandomValues(bytes);
    return Array.prototype.map.call(bytes, function (n) {
      return ALPHABET.charAt(n % ALPHABET.length);
    }).join('');
  }
  function kindOf(raw) {
    if (/^tel:/i.test(raw)) return 'phone';
    if (/^mailto:/i.test(raw)) return 'email';
    if (/^whatsapp:/i.test(raw)) return 'whatsapp';
    try {
      var url = new URL(raw, location.href);
      if (/^(wa\.me|wa\.link|api\.whatsapp\.com|web\.whatsapp\.com|chat\.whatsapp\.com)$/i.test(url.hostname)) {
        return 'whatsapp';
      }
    } catch (_) {}
    return '';
  }
  function whereIs(el) {
    if (!el || !el.closest) return 'contenido';
    if (el.closest('#tbx-wa,.ht-ctc-chat,.ht_ctc_chat_style,.joinchat,.qlwapp')) return 'flotante';
    if (el.closest('[class*="social"],[class*="redes"]')) {
      return el.closest('footer,.elementor-location-footer') ? 'redes-footer' :
        el.closest('header,.elementor-location-header') ? 'redes-header' : 'redes';
    }
    if (el.closest('header,nav,.elementor-location-header')) return 'header';
    if (el.closest('footer,.elementor-location-footer')) return 'footer';
    if (el.closest('.elementor-popup-modal,[role="dialog"]')) return 'popup';
    return 'contenido';
  }
  function numberOf(href) {
    try {
      var u = new URL(href, location.href);
      var number = u.searchParams.get('phone') ||
        (/^(wa\.me)$/i.test(u.hostname) ? u.pathname : '');
      number = digits(number);
      return /^[0-9]{8,15}$/.test(number) ? number : '';
    } catch (_) { return ''; }
  }
  function messageTemplate() {
    var page = String(document.title || '').split(/\s[|–—\-·]\s/)[0].trim();
    return String(CONFIG.message || '')
      .replace(/\{pagina\}/g, page)
      .replace(/\{sitio\}/g, String(CONFIG.site || location.hostname || ''))
      .replace(/\{url\}/g, location.origin + location.pathname).slice(0, 500);
  }
  function appendRef(a, ref) {
    if (!a || !ref) return '';
    try {
      var u = new URL(a.href, location.href);
      if (!['https:', 'http:', 'whatsapp:'].includes(u.protocol)) return '';
      var number = numberOf(a.href);
      if (!number && CONFIG.unify && /^[0-9]{8,15}$/.test(digits(CONFIG.number))) {
        number = digits(CONFIG.number);
        u = new URL('https://wa.me/' + number);
        u.searchParams.set('text', messageTemplate());
      }
      if (!number) return '';
      var message = (u.searchParams.get('text') || '')
        .replace(/\s*Ref: [A-HJ-NP-Z2-9]{6}\s*$/i, '').trim();
      u.searchParams.set('text', (message ? message + '\n\n' : '') + 'Ref: ' + ref);
      a.href = u.toString();
      if (a.id === 'tbx-wa') {
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
      }
      return number;
    } catch (_) { return ''; }
  }
  function attribution() {
    var c = {};
    try {
      var q = new URLSearchParams(location.search);
      ['source','medium','campaign'].forEach(function (key) {
        var v = q.get('utm_' + key);
        c[key] = String(v || '').slice(0, 100);
      });
      if (!c.source && !c.medium && !c.campaign && window.sessionStorage) {
        var previous = JSON.parse(window.sessionStorage.getItem('tbx_campaign') || '{}');
        ['source','medium','campaign'].forEach(function (key) {
          c[key] = String(previous[key] || '').slice(0, 100);
        });
      }
      if (!c.source && document.referrer) {
        var u = new URL(document.referrer);
        if (u.hostname !== location.hostname && /^https?:$/.test(u.protocol)) {
          c.source = u.hostname.slice(0, 100);
          c.medium = 'referral';
        }
      }
    } catch (_) {}
    return c;
  }
  function send(payload) {
    if (window.TBX_ANALYTICS_ALLOWED === false || navigator.globalPrivacyControl === true) return;
    try {
      var body = JSON.stringify(payload);
      if (navigator.sendBeacon &&
          navigator.sendBeacon(ENDPOINT, new Blob([body], {type: 'application/json'}))) return;
      fetch(ENDPOINT, {
        method:'POST', headers:{'Content-Type':'application/json'},
        body:body, credentials:'omit', keepalive:true
      }).catch(function () {});
    } catch (_) {}
  }

  document.addEventListener('click', function (event) {
    if (!event.target || !event.target.closest) return;
    var a = event.target.closest('a[href]');
    var href = a ? (a.getAttribute('href') || '') : '';
    var kind = kindOf(href);
    var widget = null;
    if (!kind) {
      widget = event.target.closest(WA_WIDGETS);
      if (!widget) return;
      kind = 'whatsapp';
      if (!a && widget.querySelector) a = widget.querySelector('a[href]');
      href = a ? (a.getAttribute('href') || '') : '';
    }
    var el = a || widget || event.target;
    var now = Date.now();
    if (now - (last.get(el) || 0) < 1500) return;
    last.set(el, now);

    var ref = '', destination = '';
    if (kind === 'whatsapp') {
      var proposal = randomCode();
      destination = a ? appendRef(a, proposal) : '';
      ref = destination ? proposal : ''; // Nunca guardar referencia que no se insertó en el enlace.
    } else if (kind === 'email') {
      destination = href.replace(/^mailto:/i, '').split('?')[0].slice(0, 190);
    } else {
      destination = href.replace(/^tel:/i, '').slice(0, 80);
    }

    var label = String((el.getAttribute && (el.getAttribute('data-track') ||
      el.getAttribute('aria-label') || el.getAttribute('title'))) ||
      el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 100);
    send({
      type: kind, ref: ref, destination: destination,
      origin: el.id === 'tbx-wa' ? 'boton-tibox' : 'enlace',
      button_text: label, location: whereIs(el),
      path: location.pathname, campaign: attribution()
    });
  }, true);
})();
