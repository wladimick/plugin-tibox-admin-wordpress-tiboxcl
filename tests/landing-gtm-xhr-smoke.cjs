const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('assets/tibox-landing-lead-bridge.js','utf8');

function session(config={}) {
  const dataLayer=[];
  const location={origin:'https://prodata.cl',href:'https://prodata.cl/lp/porticos/'};
  let nextLeadId=101, nextStatus=200, nextPayload=null;
  class FakeXHR {
    constructor(){this.status=0;this.responseText='';this.responseType='';
      this.response=null;this.listeners={};}
    open(method,url){this.method=method;this.url=url;return 'original open';}
    addEventListener(type,fn){(this.listeners[type] ||= []).push(fn);}
    send(body){
      this.body=body;this.status=nextStatus;
      const out=nextPayload || {
        success:true,lead_created:true,lead_id:nextLeadId,
        submission_id:'lead-'+nextLeadId
      };
      this.response=out;
      this.responseText=JSON.stringify(out);
      for(const cb of this.listeners.load||[]) cb.call(this);
      return 'original send';
    }
  }
  const nativeOpen=FakeXHR.prototype.open;
  const nativeSend=FakeXHR.prototype.send;
  const window={
    location,dataLayer,XMLHttpRequest:FakeXHR,
    fetch:async()=>({ok:true,clone(){return {json:async()=>({success:false,lead_created:false})};}}),
    TBX_LANDING_GTM_COMPAT:{enabled:config.enabled!==false},
    TBX_ANALYTICS_ALLOWED:config.consent!==false,
    __tbxLeads:Boolean(config.legacy)
  };
  vm.runInNewContext(source,{window,navigator:{
    globalPrivacyControl:Boolean(config.gpc)
  },location,URL,URLSearchParams,JSON,Number,Set,Promise,Date});
  return {
    window,dataLayer,nativeOpen,nativeSend,
    changeResult:(id,status,payload)=>{nextLeadId=id;nextStatus=status;nextPayload=payload||null;},
    request(method,url,body,responseType=''){
      const xhr=new window.XMLHttpRequest();
      xhr.responseType=responseType;
      assert.equal(xhr.open(method,url),'original open');
      assert.equal(xhr.send(body),'original send');
      return xhr;
    }
  };
}

let s=session();
let xhr=s.request('POST','/wp-json/tibox/v1/lead',
  JSON.stringify({form_id:'porticos',name:'Nombre',email:'privado@example.cl'}));
assert.equal(s.dataLayer.length,1);
assert.equal(s.dataLayer[0].event,'tbx_generate_lead');
assert.equal(s.dataLayer[0].form_id,'porticos');
assert.equal(s.dataLayer[0].origen_formulario,'html');
assert.equal(JSON.stringify(s.dataLayer).includes('privado@example.cl'),false);
assert.equal(xhr.status,200,'Bridge must not change XHR status');
assert.equal(xhr.response.success,true,'Bridge must not consume the original XHR response');
s.request('POST','/wp-json/tibox/v1/lead',
  JSON.stringify({form_id:'porticos'}));
assert.equal(s.dataLayer.length,1,'Replayed saved lead should not create another conversion');
s.changeResult(102,200);
s.request('POST','/wp-json/tibox/v1/lead',
  JSON.stringify({form_id:'contacto'}),'json');
assert.equal(s.dataLayer.length,2,'XHR responseType=json must work');

s.changeResult(103,200,{success:true,lead_created:false,lead_id:103});
s.request('POST','/wp-json/tibox/v1/lead','form_id=formulario');
assert.equal(s.dataLayer.length,2,'Honeypot response must not count');
s.changeResult(104,422,{success:false,lead_created:false,lead_id:104});
s.request('POST','/wp-json/tibox/v1/lead','form_id=formulario');
assert.equal(s.dataLayer.length,2,'Validation errors must not count');

s.changeResult(105,200);
s.request('GET','/wp-json/tibox/v1/lead','form_id=formulario');
s.request('POST','https://otro.cl/wp-json/tibox/v1/lead','form_id=formulario');
s.request('POST','/wp-json/tibox/v1/otro','form_id=formulario');
assert.equal(s.dataLayer.length,2,'Unrelated requests must not count');

for (const config of [{enabled:false},{legacy:true},{consent:false},{gpc:true}]) {
  s=session(config);
  assert.equal(s.window.XMLHttpRequest.prototype.open,s.nativeOpen,
    'Disabled bridge must not patch XHR prototype');
  s.request('POST','/wp-json/tibox/v1/lead','form_id=cotizar');
  assert.equal(s.dataLayer.length,0);
}
console.log('TIBOX native landing XHR conversion bridge tests passed');
