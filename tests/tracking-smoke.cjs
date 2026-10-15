/* Test básico de privacidad y eventos sin dependencia de WordPress / DOM real. */
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const src = fs.readFileSync('assets/tibox-tracking.js', 'utf8');

const listeners = {};
const sent = [];
const timers = [];
const storage = new Map();
const location = { pathname: '/lp/porticos/', search: '?utm_source=google&utm_medium=cpc&utm_campaign=porticos', host: 'prodata.cl', href: 'https://prodata.cl/lp/porticos/' };
const document = {
  readyState: 'loading', visibilityState: 'visible', referrer: '',
  addEventListener: (name, fn) => { (listeners[name] ||= []).push(fn); },
  querySelectorAll: () => [],
  documentElement: { scrollHeight: 1800 }
};
const window = {
  location, dataLayer: [], innerHeight: 800, pageYOffset: 0,
  addEventListener: (name, fn) => { (listeners['window_' + name] ||= []).push(fn); },
  requestAnimationFrame: (fn) => fn()
};
const sandbox = {
  window, document, location, URL, URLSearchParams, Date,
  sessionStorage: {
    setItem: (k, v) => storage.set(k, v),
    getItem: k => storage.get(k) || null
  },
  navigator: { globalPrivacyControl: false, sendBeacon: (url, blob) => { sent.push({url, body: JSON.parse(blob.parts.join(''))}); return true; } },
  Blob: class { constructor(parts) { this.parts = parts; } },
  setInterval: (fn) => timers.push(fn), setTimeout: () => {},
  IntersectionObserver: class { observe() {} },
};
vm.runInNewContext(src, sandbox);
assert.equal(window.__tbxTracking, true);
assert.equal(window.dataLayer.length, 0, 'page_view own endpoint only');
for (const fn of listeners.visibilitychange) fn();
assert.equal(sent.length, 1);
assert.equal(sent[0].body.events[0].e, 'page_view');
assert.equal(sent[0].body.campaign.source, 'google');
assert.equal(sent[0].body.campaign.medium, 'cpc');
assert.equal(sent[0].body.campaign.campaign, 'porticos');
assert.equal('visitor_id' in sent[0].body, false);
assert.equal('user_agent' in sent[0].body, false);

// Descarga y enlace externo se agregan solo al endpoint propio, nunca a dataLayer.
function anchor(url, label) {
  return {
    textContent: label, hasAttribute: () => false, getAttribute: k => k === 'href' ? url : '',
    closest: (s) => s === 'a, button' || s === 'a[href]' ? anchorRef : null,
    matches: () => false
  };
}
let anchorRef = anchor('https://prodata.cl/brochure.pdf', 'Brochure');
for (const fn of listeners.click || []) fn({target: anchorRef});
anchorRef = anchor('https://externo.cl/', 'Enlace externo');
for (const fn of listeners.click || []) fn({target: anchorRef});
for (const fn of listeners.visibilitychange) fn();
const evs = sent[1].body.events.map(x => x.e);
assert.ok(evs.includes('download_click'));
assert.ok(evs.includes('outbound_click'));
assert.equal(window.dataLayer.length, 0, 'new events must not duplicate GTM conversions');

// Respeto explícito a opt-out de consentimiento.
const win2 = { TBX_ANALYTICS_ALLOWED: false };
vm.runInNewContext(src, {...sandbox, window: win2});
assert.equal(win2.__tbxTracking, undefined);
console.log('Tracking smoke tests passed');
