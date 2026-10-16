const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const native = fs.readFileSync('assets/tibox-form-conversions.js', 'utf8');
const bridge = fs.readFileSync('assets/tibox-landing-lead-bridge.js', 'utf8');

async function run() {
  const listeners={}, dl=[];
  const location={
    origin:'https://tibox.cl',href:'https://tibox.cl/lp/porticos/',
    pathname:'/lp/porticos/',hostname:'tibox.cl'
  };
  let leadId = 101;
  const realFetch = async () => ({
    ok:true,
    clone() { return {json: async () => ({
      success:true,lead_created:true,lead_id:leadId,
      submission_id:'submission-'+leadId
    })};}
  });
  const document={
    cookie:'',
    addEventListener(type,callback){(listeners[type] ||= []).push(callback);}
  };
  const window={
    location,dataLayer:dl,fetch:realFetch,
    TBX_FORM_GTM_COMPAT:{enabled:true},
    TBX_LANDING_GTM_COMPAT:{enabled:true},
    TBX_ANALYTICS_ALLOWED:true,
    jQuery:()=>({on(){}})
  };
  const sandbox={
    window,document,navigator:{globalPrivacyControl:false},
    location,URL,URLSearchParams,Promise,Date,JSON,Number,Set,decodeURIComponent
  };
  vm.runInNewContext(native,sandbox);
  vm.runInNewContext(bridge,sandbox);
  function submit(id) {
    const f={
      id,matches:(sel)=>sel==='form[data-tbx-track-form]',
      getAttribute:(attr)=>attr==='data-tbx-track-form'?id:
        attr==='data-tbx-name'?'Cotización':''
    };
    for(const listener of listeners['tbx:form-success']||[]) {
      listener({target:f,detail:{form:f,formId:id,formName:'Cotización'}});
    }
  }
  async function fetchSuccess(id) {
    await window.fetch('/wp-json/tibox/v1/lead',{
      method:'POST',
      body:JSON.stringify({form_id:id,name:'No debe llegar a GTM',
        email:'privado@example.cl'})
    });
    await new Promise(resolve=>setImmediate(resolve));
  }

  await fetchSuccess('cotizacion');
  assert.equal(dl.length,1,'Native REST success must emit one conversion');
  submit('cotizacion');
  assert.equal(dl.length,1,'A second success signal from HTML must not duplicate same lead');
  leadId=102;
  submit('contacto');
  await fetchSuccess('contacto');
  assert.equal(dl.length,2,'HTML signal followed by REST success must still be one lead');
  assert.equal(dl[0].form_id,'cotizacion');
  assert.equal(dl[1].form_id,'contacto');
  assert.ok(dl.every(x=>Object.keys(x).sort().join(',')===
    ['event','form_id','form_name','origen_formulario'].sort().join(',')));
  assert.equal(JSON.stringify(dl).includes('privado@example.cl'),false);
  console.log('Shared landing REST and explicit HTML GTM event deduplication tests passed');
}
run().catch(e=>{console.error(e);process.exitCode=1;});
