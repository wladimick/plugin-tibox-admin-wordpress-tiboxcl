const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const code = fs.readFileSync('assets/tibox-contact-clicks.js','utf8');
const buttonCode = fs.readFileSync('assets/tibox-wa-button.js','utf8');
const created = [], listeners = {}, events = [];
function element(tag) {
  const attrs = {};
  return {
    tagName: tag, id: '', textContent: '', href:'', children:[],
    setAttribute: (k,v) => { attrs[k] = v; },
    getAttribute: k => attrs[k] || (k === 'href' ? el.href : ''),
    appendChild: function(v) {this.children.push(v);},
    closest: function(s) {return s.includes('#tbx-wa') ? this : null;},
    hasAttribute: ()=>false
  };
}
let el;
const document = {
  title:'Pórticos de seguridad | Prodata',
  readyState: 'complete',
  body: { appendChild: node => created.push(node) },
  createElement: name => element(name),
  getElementById: () => null,
  addEventListener: (key,fn) => (listeners[key] ||= []).push(fn),
};
const location={href:'https://prodata.cl/porticos/',pathname:'/porticos/',hostname:'prodata.cl',host:'prodata.cl',search:''};
const window = {
  location,
  TBX_CONTACT_WA_BUTTON: {number:'56912345678',label:'Cotiza con nosotros',message:'Hola desde {sitio}, veo {pagina}',side:'left'},
  crypto: {getRandomValues: bytes => { for (let i=0;i<bytes.length;i++) bytes[i] = i+10; }},
  addEventListener:()=>{}
};
class Blob {constructor(parts){this.parts=parts;}}
const navigator={globalPrivacyControl:false, sendBeacon:(ep,body)=>{events.push(JSON.parse(body.parts.join('')));return true;}};
const sandbox = {window,document,location,navigator,URL,URLSearchParams,Date,Uint8Array,Blob,encodeURIComponent};
vm.runInNewContext(code,sandbox);
vm.runInNewContext(buttonCode,sandbox);
assert.equal(created.length,1);
el=created[0];
assert.equal(el.id,'tbx-wa');
assert.match(el.className,/--left/);
assert.match(new URL(el.href).searchParams.get('text'),/Hola desde prodata.cl, veo Pórticos de seguridad/);
assert.equal(el.children[1].textContent,'Cotiza con nosotros');
assert.match(el.children[0].innerHTML, /<svg viewBox="0 0 32 32"/, 'WA CTA needs a recognizable chat icon');
for (const fn of listeners.click) fn({target:{closest: sel => sel === 'a[href]' ? el : null}});
assert.equal(events.length,1);
assert.equal(events[0].type,'whatsapp');
assert.equal(events[0].location,'flotante');
assert.match(new URL(el.href).searchParams.get('text'), /Ref: [A-HJ-NP-Z2-9]{6}$/);
// El botón debe mostrarse incluso si la captura de contactos y la analítica
// están desactivadas (no se debe cargar el script de eventos en este caso).
const disabled = [], disabledListeners = {};
const doc2 = {
  title: document.title, readyState: 'complete',
  body: {appendChild: el => disabled.push(el)},
  createElement: element,
  getElementById: () => null,
  addEventListener: (key,fn) => (disabledListeners[key] ||= []).push(fn)
};
const win2 = {
  location, TBX_ANALYTICS_ALLOWED: false,
  TBX_CONTACT_WA_BUTTON: window.TBX_CONTACT_WA_BUTTON,
  addEventListener: () => {}
};
vm.runInNewContext(buttonCode,{
  window: win2, document: doc2, location, navigator: {globalPrivacyControl:true},
  URL, URLSearchParams, Date, Uint8Array, Blob, encodeURIComponent
});
assert.equal(disabled.length, 1, 'WhatsApp button must not depend on native capture or analytics consent');
assert.equal(disabledListeners.click, undefined, 'A standalone button must not create a tracking listener');
console.log('Independent WhatsApp floating CTA smoke tests passed');
