const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const script = fs.readFileSync('assets/tibox-form-conversions.js', 'utf8');

function create(enabled = true, options = {}) {
  const dom = {}, jq = {}, pushed = [];
  const document = {
    cookie: options.cookie || '',
    addEventListener: (type, handler) => { (dom[type] ||= []).push(handler); }
  };
  const jQuery = () => ({on: (name, selector, fn) => {
    if (typeof selector === 'function') fn = selector;
    (jq[name] ||= []).push(fn);
  }});
  const window = {
    jQuery, dataLayer: pushed, __tbxLeads: Boolean(options.legacy),
    TBX_ANALYTICS_ALLOWED: options.consent !== false,
    TBX_FORM_GTM_COMPAT: {enabled}
  };
  const navigator = {globalPrivacyControl: Boolean(options.gpc)};
  vm.runInNewContext(script, {window, navigator, document, Date, decodeURIComponent});
  function fire(kind, data) {
    const handlers = kind.startsWith('jquery:') ? jq[kind.slice(7)] : dom[kind];
    for (const handler of handlers || []) handler(data);
  }
  return {fire, pushed, document, jq, dom, window};
}

function form(type, id, name) {
  const classes = {
    'form.wpforms-form': type === 'wpforms',
    'form.elementor-form': type === 'elementor',
    'form.wpcf7-form': type === 'cf7',
    'form[data-tbx-track-form]': type === 'html'
  };
  return {
    id: type === 'wpforms' ? 'wpforms-form-' + id : id,
    matches: selector => Boolean(classes[selector]),
    closest: selector => Boolean(classes[selector]) ? this : null,
    getAttribute: key => ({
      'data-formid': type === 'wpforms' ? id : '',
      'aria-label': name, 'data-formname': name,
      'name': name, 'data-tbx-track-form': id, 'data-tbx-name': name
    }[key] || ''),
    querySelector: selector => {
      if (type === 'elementor') {
        if (selector.includes('form_id')) return {value: id};
        if (selector.includes('form_name')) return {value: name};
      }
      if (type === 'cf7' && selector.includes('_wpcf7')) return {value: id};
      return null;
    }
  };
}
function evt(target, detail) {
  return {target: {
    ...target,
    closest: selector => target.matches && target.matches(selector) ? target : null
  }, detail};
}

let s = create();
const wp = form('wpforms', '830', 'Cotización');
s.fire('jquery:wpformsAjaxSubmitSuccess', evt(wp));
assert.equal(s.pushed.length, 1);
assert.deepEqual(Object.keys(s.pushed[0]).sort(),
  ['event','form_id','form_name','origen_formulario'].sort());
assert.equal(s.pushed[0].event, 'tbx_generate_lead');
assert.equal(s.pushed[0].form_id, '830');
assert.equal(s.pushed[0].origen_formulario, 'wpforms');
s.fire('jquery:wpformsAjaxSubmitSuccess', evt(wp));
assert.equal(s.pushed.length, 1, 'Duplicate AJAX success must not convert again');
s.fire('submit', evt(wp));
assert.equal(s.pushed.length, 1, 'Plain submit is not confirmed success');

const el = form('elementor', 'contacto-prodata', 'Formulario sitio');
s.fire('jquery:submit_success', evt(el));
assert.equal(s.pushed.length, 2);
assert.equal(s.pushed[1].origen_formulario, 'elementor');
assert.equal(s.pushed[1].form_id, 'contacto-prodata');
s.fire('jquery:submit_success', evt(el));
assert.equal(s.pushed.length, 2, 'Elementor success double hook must be deduped');

const cf = form('cf7','106','Contacta');
s.fire('wpcf7mailsent', evt(cf,{contactFormId: '106'}));
assert.equal(s.pushed.length,3);
assert.equal(s.pushed[2].origen_formulario,'cf7');
assert.equal(s.pushed[2].form_id,'106');

const html = form('html','cotiza-html','Contacto personalizado');
s.fire('tbx:form-success',evt(html,{form:html,formId:'cotiza-html',formName:'Contacto personalizado'}));
assert.equal(s.pushed.length,4);
assert.equal(s.pushed[3].origen_formulario,'html');
s.fire('tbx:form-success',evt(html,{form:html,formId:'cotiza-html'}));
assert.equal(s.pushed.length,4, 'One successful HTML submit = one conversion');

s = create(false);
s.fire('jquery:wpformsAjaxSubmitSuccess',evt(wp));
assert.equal(s.pushed.length,0, 'Disabled compat must not send conversions');

s = create(true,{legacy:true});
s.fire('jquery:wpformsAjaxSubmitSuccess',evt(wp));
assert.equal(s.pushed.length,0, 'TIBOX Leads active must own its conversions');

s = create(true,{consent:false});
s.fire('jquery:wpformsAjaxSubmitSuccess',evt(wp));
assert.equal(s.pushed.length,0, 'Consent deny must block conversion compat');

s = create(true,{gpc:true});
s.fire('jquery:wpformsAjaxSubmitSuccess',evt(wp));
assert.equal(s.pushed.length,0, 'GPC must block conversion compat');

s = create(true,{cookie:'theme=light; tbx_admin_form_gtm=830'});
assert.equal(s.pushed.length,1,'Non-AJAX WPForms success must be picked up once');
assert.equal(s.pushed[0].form_id,'830');
assert.match(s.document.cookie,/max-age=0/,'Fallback cookie must be cleared');
s = create(true,{cookie:'tbx_admin_form_gtm=%3Cscript%3E'});
assert.equal(s.pushed.length,0,'Invalid cookie ID must never produce conversion');

console.log('Legacy form conversion parity and no-duplicate smoke tests passed');
