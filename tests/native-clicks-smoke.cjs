const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const src = fs.readFileSync('assets/tibox-contact-clicks.js', 'utf8');

function session(gpc = false, allowed = true, cfg = null) {
  const handlers = {}, sent = [];
  const document = { addEventListener: (name, fn) => { (handlers[name] ||= []).push(fn); } };
  const location = { href: 'https://prodata.cl/lp/porticos/', pathname: '/lp/porticos/', search: '?utm_source=google&utm_medium=cpc' };
  const window = {
    location, TBX_ANALYTICS_ALLOWED: allowed, TBX_CONTACT_LINK_CONFIG: cfg,
    crypto: { getRandomValues: (bytes) => { for(let i=0;i<bytes.length;i++) bytes[i]=i+1; } },
    sessionStorage: { getItem: () => null }
  };
  class Blob { constructor(parts) { this.parts = parts; } }
  const navigator = { globalPrivacyControl: gpc, sendBeacon: (endpoint, blob) => {
    sent.push({ endpoint, value: JSON.parse(blob.parts.join('')) }); return true;
  }};
  vm.runInNewContext(src, { window, document, navigator, Blob, URL, URLSearchParams, location, Date, Uint8Array });
  return { handlers, sent, window };
}
let x = session(true);
assert.equal(x.handlers.click, undefined, 'GPC must block handler');
x = session(false, false);
assert.equal(x.handlers.click, undefined, 'site consent denial must block handler');

x = session();
function el(href, label='Cotiza') {
  return {
    href,
    textContent: label,
    getAttribute: (key) => key === 'href' ? href : key === 'aria-label' ? label : '',
    closest: () => null
  };
}
function click(a) {
  const t = { closest: (selector) => selector === 'a[href]' ? a : null };
  for (const handler of x.handlers.click) handler({ target: t });
}
const whatsapp = el('https://wa.me/56912345678?text=Hola');
click(whatsapp);
assert.equal(x.sent.length,1);
assert.equal(x.sent[0].value.type,'whatsapp');
assert.equal(x.sent[0].value.destination,'56912345678');
assert.match(x.sent[0].value.ref,/^[A-HJ-NP-Z2-9]{6}$/);
assert.match(new URL(whatsapp.href).searchParams.get('text'), /Ref: [A-HJ-NP-Z2-9]{6}$/);
assert.equal(x.sent[0].value.campaign.source,'google');
assert.equal(x.sent[0].value.path,'/lp/porticos/');
click(whatsapp);
assert.equal(x.sent.length,1, 'Double click must be deduplicated');
click(el('tel:+56987654321','Llamar'));
click(el('mailto:ventas@prodata.cl','Correo'));
assert.deepEqual(x.sent.map(row => row.value.type),['whatsapp','phone','email']);
assert.ok(x.sent.every(row => !('visitor_id' in row.value)));

x = session();
click = function () {}; // The event below uses this session directly.
function clicked(sess, anchor) {
  const target = { closest: selector => selector === 'a[href]' ? anchor : null };
  for (const handler of sess.handlers.click) handler({target});
}
const widgetLink = el('https://api.whatsapp.com/send?phone=56911112222&text=Hola','Consulta');
clicked(x, widgetLink);
assert.equal(x.sent[0].value.destination, '56911112222');
assert.match(new URL(widgetLink.href).searchParams.get('text'), /Ref: [A-HJ-NP-Z2-9]{6}$/);
const deepLink = el('whatsapp://send?phone=56911112222&text=Hola','Abrir WA');
clicked(x, deepLink);
assert.equal(x.sent[1].value.type, 'whatsapp');
assert.equal(x.sent[1].value.destination, '56911112222');
const shortLink = el('https://wa.link/abcdefgh','WhatsApp corto');
clicked(x, shortLink);
assert.equal(x.sent[2].value.ref, '', 'Shortlinks must not invent traceable reference');
assert.equal(x.sent[2].value.type, 'whatsapp');
const ctx = {unify:true,number:'56988887777',message:'Cotizar {pagina}',site:'Prodata'};
x = session(false,true,ctx);
const secondNumber = el('https://wa.me/56911112222?text=Original','Cotizar');
clicked(x, secondNumber);
assert.equal(x.sent[0].value.destination,'56988887777','Unify must redirect to configured number');
assert.match(new URL(secondNumber.href).searchParams.get('text'),/Ref: [A-HJ-NP-Z2-9]{6}$/);
assert.ok(x.sent.every(row => !('visitor_id' in row.value)));
console.log('Native contact click client smoke tests passed');
