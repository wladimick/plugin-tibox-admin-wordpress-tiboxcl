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
    icon.textContent = '✆';
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
