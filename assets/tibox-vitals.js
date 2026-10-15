/*!
 * Optional real-user Core Web Vitals collector.
 * Only reports LCP, CLS and INP values to a same-origin endpoint that
 * immediately aggregates them into ratings; no individual values are stored.
 * Library: GoogleChrome/web-vitals 5.3.0 (Apache-2.0), pinned CDN build.
 */
(function () {
  'use strict';
  if (window.__tbxVitals ||
      window.TBX_ANALYTICS_ALLOWED === false ||
      navigator.globalPrivacyControl === true ||
      !document.createElement) return;
  window.__tbxVitals = true;

  var endpoint = window.TBX_VITALS_ENDPOINT || '/wp-json/tibox/v1/vitals';
  var latest = {};
  var sent = {};
  var path = window.location.pathname;
  var valid = { LCP: true, CLS: true, INP: true };
  var flushing = false;

  function collect(metric) {
    if (!metric || !valid[metric.name] || !Number.isFinite(metric.value) || metric.value < 0) return;
    if (sent[metric.name]) return;
    latest[metric.name] = metric.value;
    if (document.visibilityState === 'hidden') scheduleFlush();
  }

  function flush() {
    flushing = false;
    if (window.TBX_ANALYTICS_ALLOWED === false || navigator.globalPrivacyControl === true) {
      latest = {};
      return;
    }
    var batch = [];
    Object.keys(latest).forEach(function (name) {
      if (!sent[name]) {
        batch.push({ name: name, value: latest[name] });
        sent[name] = true;
      }
    });
    latest = {};
    if (!batch.length) return;
    var data = JSON.stringify({ path: path, metrics: batch });
    try {
      if (navigator.sendBeacon &&
          navigator.sendBeacon(endpoint, new Blob([data], { type: 'application/json' }))) return;
      fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'omit',
        keepalive: true,
        body: data
      }).catch(function () {});
    } catch (_) {}
  }
  function scheduleFlush() {
    if (flushing) return;
    flushing = true;
    setTimeout(flush, 0);
  }

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') scheduleFlush();
  });
  window.addEventListener('pagehide', scheduleFlush);

  var loader = document.createElement('script');
  loader.async = true;
  loader.src = 'https://cdn.jsdelivr.net/npm/web-vitals@5.3.0/dist/web-vitals.iife.js';
  loader.onload = function () {
    if (window.TBX_ANALYTICS_ALLOWED === false || navigator.globalPrivacyControl === true) return;
    if (!window.webVitals) return;
    ['onLCP', 'onCLS', 'onINP'].forEach(function (method) {
      if (typeof window.webVitals[method] === 'function') {
        window.webVitals[method](collect);
      }
    });
  };
  loader.onerror = function () { /* degradación silenciosa: nunca bloquea el sitio */ };
  document.head.appendChild(loader);
})();
