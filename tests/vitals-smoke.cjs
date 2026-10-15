const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const src = fs.readFileSync('assets/tibox-vitals.js', 'utf8');
function boot(opts = {}) {
  const sent = [];
  const timers = [];
  const listeners = {};
  const loaders = [];
  const doc = {
    visibilityState: 'visible',
    createElement: () => ({ async: false, src: '', onload: null, onerror: null }),
    head: { appendChild: (node) => loaders.push(node) },
    addEventListener: (event, fn) => { (listeners[event] ||= []).push(fn); }
  };
  const win = {
    TBX_ANALYTICS_ALLOWED: opts.allowed,
    TBX_VITALS_ENDPOINT: '/wp-json/tibox/v1/vitals',
    location: { pathname: '/lp/pizarras/' },
    addEventListener: (event, fn) => { (listeners[event] ||= []).push(fn); },
    webVitals: {
      onLCP: (fn) => { win.lcp = fn; },
      onCLS: (fn) => { win.cls = fn; },
      onINP: (fn) => { win.inp = fn; }
    }
  };
  class Blob { constructor(parts) { this.parts = parts; } }
  const sandbox = {
    window: win, document: doc,
    navigator: { globalPrivacyControl: opts.gpc === true, sendBeacon: (_, body) => {sent.push(JSON.parse(body.parts.join(''))); return true;} },
    Blob, setTimeout: (fn) => { timers.push(fn); }, Number, Object
  };
  vm.runInNewContext(src, sandbox);
  return {sent, timers, loaders, listeners, win, doc, flushTimers: () => {while (timers.length) timers.shift()();}};
}
let x = boot({allowed:false});
assert.equal(x.loaders.length, 0);
assert.equal(x.win.__tbxVitals, undefined);
x = boot({gpc:true});
assert.equal(x.loaders.length, 0);
x = boot();
assert.equal(x.loaders.length, 1);
assert.match(x.loaders[0].src, /web-vitals@5\.3\.0/);
x.loaders[0].onload();
x.win.lcp({ name: 'LCP', value: 2400 });
x.win.cls({ name: 'CLS', value: 0.08 });
x.win.inp({ name: 'INP', value: 184 });
x.win.lcp({ name: 'LCP', value: -1 });
x.doc.visibilityState = 'hidden';
for (const listener of x.listeners.visibilitychange) listener();
x.flushTimers();
assert.equal(x.sent.length, 1);
assert.equal(x.sent[0].path, '/lp/pizarras/');
assert.equal(x.sent[0].metrics.length, 3);
assert.equal(Object.keys(x.sent[0]).includes('visitor_id'), false);
for (const listener of x.listeners.pagehide) listener();
x.flushTimers();
assert.equal(x.sent.length, 1, 'Must not send duplicate metric on pagehide');
console.log('Core Web Vitals client smoke tests passed');
