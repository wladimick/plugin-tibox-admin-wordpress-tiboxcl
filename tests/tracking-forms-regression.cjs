// Regression: the existing TIBOX Activity GTM event wpforms_submit must
// survive the transition and never fire twice for one confirmed submission.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const src = fs.readFileSync('assets/tibox-tracking.js', 'utf8');

const handlers = {}, jq = {}, saved = [];
const document = {
  readyState: 'complete', visibilityState: 'visible', referrer: '',
  title: 'Prodata | Contacto',
  addEventListener(type, handler) { (handlers[type] ||= []).push(handler); },
  querySelectorAll() { return []; }
};
const location = {
  pathname:'/contacto/', search:'?utm_source=google&utm_medium=cpc',
  hostname:'prodata.cl', host:'prodata.cl', href:'https://prodata.cl/contacto/'
};
const jquery = () => ({
  on(events, selector, handler) {
    if (typeof selector === 'function') handler = selector;
    for (const e of events.split(' ')) (jq[e] ||= []).push(handler);
  }
});
const window = {
  location, jQuery:jquery, dataLayer:[],
  addEventListener(type, handler) { (handlers[type] ||= []).push(handler); },
  requestAnimationFrame(fn) { fn(); },
  innerHeight:800, pageYOffset:0
};
const sandbox = {
  window, document, location, URL, URLSearchParams, navigator:{
    globalPrivacyControl:false,
    sendBeacon(endpoint,blob) {
      saved.push(JSON.parse(blob.parts.join(''))); return true;
    }
  }, sessionStorage:{getItem(){return null;},setItem(){}},
  Blob:class{constructor(parts){this.parts=parts;}},
  setInterval(){}, setTimeout(){}, Date
};
vm.runInNewContext(src,sandbox);
assert.ok(jq.wpformsAjaxSubmitSuccess, 'WPForms AJAX success must be observed');
function form(id) {
  return {
    id:'wpforms-form-'+id,
    __tbxSuccessAt:0,
    getAttribute(name) { return name === 'data-formid' ? String(id) : ''; },
    matches(selector) { return selector === 'form.wpforms-form' ||
      selector.includes('form.wpforms-form'); },
    closest(selector) {return selector === 'form.wpforms-form' ? this : null;},
    querySelector(){return null;}
  };
}
const a=form(830);
for (const fn of jq.wpformsAjaxSubmitSuccess) fn({target:document}, {}, {jquery:true,0:a});
assert.equal(window.dataLayer.length,1,'Confirmed success must emit wpforms_submit');
assert.equal(window.dataLayer[0].event,'wpforms_submit');
assert.equal(window.dataLayer[0].form_name,'wpforms-830');
assert.equal(window.dataLayer[0].form_type,'lead');
for (const fn of jq.wpformsAjaxSubmitSuccess) fn({target:document}, {}, {jquery:true,0:a});
assert.equal(window.dataLayer.length,1,'Duplicate success must not emit another conversion');
const b=form(10646);
for (const fn of jq.wpformsAjaxSubmitSuccess) fn({target:b});
assert.equal(window.dataLayer.length,2,'Independent confirmed submissions must be counted');
for (const fn of jq.wpformsAjaxSubmitFailed) fn({target:document}, {}, b);
assert.equal(window.dataLayer[2].event,'form_error',
  'Failed event must remain an error, never wpforms_submit');
const impossible=form(999);
for (const fn of jq.wpformsAjaxSubmitSuccess) fn({target:document}, {success:true});
assert.equal(window.dataLayer.length,3,'Unknown form target must not invent conversion');
assert.equal(window.dataLayer.filter(e=>e.event==='wpforms_submit').length,2);
console.log('TIBOX Activity existing WPForms conversion tracking regression passed');
