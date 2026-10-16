const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('assets/tibox-landing-lead-bridge.js', 'utf8');

async function scenario(options = {}) {
  const sent = [];
  const actualResponses = [];
  let realFetchCalls = 0;
  const location = {
    origin:'https://tibox.cl',
    href:'https://tibox.cl/lp/diagnostico/',
    hostname:'tibox.cl', pathname:'/lp/diagnostico/'
  };
  const originalFetch = (input, init) => {
    realFetchCalls++;
    const response = options.respond ? options.respond(input, init) : {
      ok:true,
      clone() { return { json: async () => ({
        success:true, lead_created:true, lead_id:19, submission_id:'A1'
      })};}
    };
    actualResponses.push(response);
    return Promise.resolve(response);
  };
  const window = {
    location, fetch:originalFetch, dataLayer:sent,
    TBX_LANDING_GTM_COMPAT:{enabled: options.enabled !== false},
    TBX_ANALYTICS_ALLOWED: options.consent !== false,
    __tbxLeads: Boolean(options.legacy)
  };
  const navigator = {globalPrivacyControl:Boolean(options.gpc)};
  vm.runInNewContext(source,{
    window, navigator, location, URL, URLSearchParams,
    Promise, JSON, Number, Set
  });
  async function send(path, body, method='POST') {
    const response = await window.fetch(path, {
      method, headers:{'Content-Type':'application/json'},
      body:JSON.stringify(body)
    });
    // El interceptador jamás consume la respuesta original.
    assert.ok(actualResponses.includes(response));
    await new Promise(resolve => setImmediate(resolve));
    return response;
  }
  return {window,sent,send,realCalls:()=>realFetchCalls,originalFetch};
}

async function main() {
  let t=await scenario();
  const request={
    form_id:'cotizacion_porticos',
    name:'Nombre privado', email:'contacto@example.cl',
    message:'Información sensible de prueba'
  };
  await t.send('/wp-json/tibox/v1/lead',request);
  assert.equal(t.sent.length,1,'Exactly one confirmed stored landing lead counts');
  assert.equal(t.sent[0].event,'tbx_generate_lead');
  assert.equal(t.sent[0].form_id,'cotizacion_porticos');
  assert.equal(t.sent[0].origen_formulario,'html');
  assert.equal(t.realCalls(),1,'Original fetch is called exactly once');
  assert.equal(JSON.stringify(t.sent).includes('contacto@example.cl'),false,'Never push PII');
  assert.equal(JSON.stringify(t.sent).includes('Información sensible'),false);
  await t.send('/wp-json/tibox/v1/lead',request);
  assert.equal(t.sent.length,1,'Same saved lead_id must never count twice in page');
  await t.send('/wp-json/tibox/v1/other',request);
  await t.send('https://elsewhere.cl/wp-json/tibox/v1/lead',request);
  await t.send('/wp-json/tibox/v1/lead',request,'GET');
  assert.equal(t.sent.length,1,'Wrong method/host/endpoint must not count');

  t=await scenario({respond:()=>({
    ok:true, clone(){return {json:async()=>({
      success:true,lead_created:false,lead_id:31
    })};}
  })});
  await t.send('/wp-json/tibox/v1/lead',request);
  assert.equal(t.sent.length,0,'Honeypot success without created lead is NOT conversion');

  t=await scenario({respond:()=>({
    ok:false,clone(){return {json:async()=>({
      success:true,lead_created:true,lead_id:32
    })};}
  })});
  await t.send('/wp-json/tibox/v1/lead',request);
  assert.equal(t.sent.length,0,'HTTP error must NOT convert');

  t=await scenario({respond:()=>({
    ok:true,clone(){return {json:async()=>({
      success:true,lead_created:true,lead_id:0
    })};}
  })});
  await t.send('/wp-json/tibox/v1/lead',request);
  assert.equal(t.sent.length,0,'Response without saved lead ID is not a verified conversion');

  for (const opts of [{enabled:false},{consent:false},{gpc:true},{legacy:true}]) {
    t=await scenario(opts);
    await t.send('/wp-json/tibox/v1/lead',request);
    assert.equal(t.sent.length,0,'Disabled, consent denied, GPC, legacy must block');
  }
  t=await scenario();
  t.window.TBX_ANALYTICS_ALLOWED=false;
  await t.send('/wp-json/tibox/v1/lead',request);
  assert.equal(t.sent.length,0,'Consent changes during page lifetime must be respected');

  // La página recibe exactamente el mismo error del fetch original.
  const boom = new Error('network down');
  t=await scenario({respond:()=>{throw boom;}});
  assert.throws(()=>t.window.fetch('/wp-json/tibox/v1/lead',
    {method:'POST',body:'{}'}), e=>e===boom);

  console.log('TIBOX native landing confirmed REST conversion bridge tests passed');
}
main().catch(e=>{console.error(e);process.exitCode=1;});
