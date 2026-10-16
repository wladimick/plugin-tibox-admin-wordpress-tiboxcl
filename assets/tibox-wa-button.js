/* TIBOX Admin - botón flotante independiente de capturas/analítica. */
(function () {
  'use strict';
  if (window.__tbxWaButton) return;
  window.__tbxWaButton = true;
  /* ---------- Botón flotante opcional (reutiliza el listener único de clics) ---------- */
  function mountFloatingButton() {
    var cfg = window.TBX_CONTACT_WA_BUTTON;
    if (!cfg || !document.createElement || !document.body || document.getElementById('tbx-wa')) return;
    if (window.top && window.self && window.top !== window.self) return;
    var number = String(cfg.number || '').replace(/\D/g, '');
    if (!/^[0-9]{8,15}$/.test(number)) return;
    var title = String(document.title || '').split(/\s[|–—\-·]\s/)[0].trim();
    var site = String(location.hostname || '').replace(/^www\./, '');
    var message = String(cfg.message || '')
      .replace(/\{pagina\}/g, title).replace(/\{sitio\}/g, site).slice(0, 400);
    var a = document.createElement('a');
    a.id = 'tbx-wa';
    a.className = 'tbx-native-wa ' + (cfg.side === 'left' ? 'tbx-native-wa--left' : 'tbx-native-wa--right');
    a.href = 'https://wa.me/' + number + '?text=' + encodeURIComponent(message);
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    a.setAttribute('aria-label', String(cfg.label || 'WhatsApp').slice(0, 80));
    a.setAttribute('data-track', 'boton_whatsapp_flotante');
    var icon = document.createElement('span');
    icon.className = 'tbx-native-wa__icon';
    icon.setAttribute('aria-hidden', 'true');
    // Símbolo de conversación + teléfono, sin fuentes externas ni dependencias.
    // SVG estático, sin interpolación de contenido ingresado por visitantes.
    icon.innerHTML = '<svg viewBox="0 0 32 32" width="27" height="27" fill="none" ' +
      'stroke="currentColor" stroke-width="2.4" stroke-linecap="round" ' +
      'stroke-linejoin="round" focusable="false" aria-hidden="true">' +
      '<path d="M27 15a11 11 0 0 1-16.7 9.4L5 26l1.7-5.3A11 11 0 1 1 27 15Z"/>' +
      '<path d="M12.1 10.5c.3-.5.7-.5 1-.5l1.5 2.2-.8 1.4c.9 1.8 2.3 3.1 4.2 4l1.5-.9 2.3 1.5c.2.3 0 .8-.3 1.1-.5.7-1.4 1.2-2.2 1.1-4.4-.7-8-4.3-8.6-8.5-.1-.7.5-1.2 1.4-1.4Z"/>' +
      '</svg>';
    var label = document.createElement('span');
    label.className = 'tbx-native-wa__label';
    label.textContent = String(cfg.label || 'WhatsApp').slice(0, 80);
    a.appendChild(icon);
    a.appendChild(label);
    document.body.appendChild(a);
  }
  if (document.createElement) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', mountFloatingButton);
    } else {
      mountFloatingButton();
    }
  }
})();
