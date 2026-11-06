/* Real vendor frontend scripts; Google and transport are isolated fixture stubs. */
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const {chromium} = require('playwright');

const input = process.env.MRN_WPFORMS_COMPAT_INPUT;
const output = process.env.MRN_RECAPTCHA_REPORT_DIR;
assert(input && output, 'Explicit private fixture input/report directories are required');
const root = fs.realpathSync(input);
const manifest = JSON.parse(fs.readFileSync(path.join(root, 'manifest.json'), 'utf8'));
const sha = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const read = name => {
  const file = fs.realpathSync(path.resolve(root, name));
  assert(file.startsWith(root + path.sep), 'Fixture path must stay inside its root');
  return fs.readFileSync(file);
};
const files = new Map();
files.set('/jquery.js', read(manifest.jquery.path));
assert.equal(sha(files.get('/jquery.js')), manifest.jquery.sha256);
const versions = ['2.0.2.2', '2.0.4'];
for (const version of versions) {
  const entry = manifest.versions[version];
  assert(entry, 'Exact vendor version input required: ' + version);
  const inline = read(entry.native_inline).toString();
  assert(inline.includes('var wpformsRecaptchaV3Execute = function'));
  assert(inline.includes('wpformsRecaptchaLoaded'));
  assert(inline.includes('grecaptcha.ready'));
  for (const name of ['core', 'validate', 'inputmask', 'utils']) {
    const bytes = read(entry.assets[name]);
    assert.equal(sha(bytes), entry.hashes[name], version + ' ' + name + ' source hash');
    files.set('/' + version + '/' + name + '.js', bytes);
  }
}
const loaderFile = process.env.MRN_RECAPTCHA_LOADER_ASSET || path.resolve(__dirname, '../assets/wpforms-form-loader.js');
const loader = fs.readFileSync(loaderFile);
files.set('/adapter.js', loader);
const google = `window.fixtureApiLoads++; window.fixtureReadyCallbacks = []; window.grecaptcha = {
  ready: function(callback) { if (window.fixtureMode === 'hold') window.fixtureReadyCallbacks.push(callback); else callback(); },
  execute: function() { window.fixtureExecutions++; if (window.fixtureMode === 'reject') return Promise.reject(new Error('Fixture token rejection')); return Promise.resolve(window.fixtureMode === 'empty' ? '' : 'fresh-fixture-' + window.fixtureExecutions); }
}; window.fixtureReleaseApi = function() { window.fixtureMode = 'valid'; window.fixtureReadyCallbacks.splice(0).forEach(function(callback) { callback(); }); };`;
function html(version, mode) {
  const settings = JSON.parse(read(manifest.versions[version].settings));
  assert.equal(settings.ajaxurl, '/fixture-transport');
  return `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>WPForms ${version} protected fixture</title></head><body>
    <div id="wpforms-687" class="wpforms-container wpforms-container-full"><form id="wpforms-form-687" class="wpforms-validate wpforms-form wpforms-ajax-form" data-formid="687" data-token="fixture-only" data-token-time="${Math.floor(Date.now()/1000)}" method="post" action="/fixture-transport">
    <div class="wpforms-field-container"><div class="wpforms-field wpforms-field-text" data-field-id="3"><label for="wpforms-687-field_3">Name</label><input id="wpforms-687-field_3" name="wpforms[fields][3]" type="text" class="wpforms-field-required" required></div>
    <div class="wpforms-field wpforms-field-email" data-field-id="4"><label for="wpforms-687-field_4">Email</label><input id="wpforms-687-field_4" name="wpforms[fields][4]" type="email" class="wpforms-field-required" required></div></div>
    <div class="wpforms-recaptcha-container"><input type="hidden" name="wpforms[recaptcha]"></div><input type="hidden" name="wpforms[id]" value="687"><input type="hidden" name="wpforms[post_id]" value="1">
    <div class="wpforms-submit-container"><button type="submit" id="wpforms-submit-687" class="wpforms-submit" data-submit-text="Send Message" data-alt-text="Sending">Send Message</button></div></form></div>
    <script>window.wpforms_settings=${JSON.stringify(settings)}; window.fixtureMode=${JSON.stringify(mode)}; window.fixtureExecutions=0; window.fixtureApiLoads=0;</script>
    <script src="/jquery.js"></script><script src="/${version}/core.js"></script><script src="/${version}/validate.js"></script><script src="/${version}/inputmask.js"></script><script src="/${version}/utils.js"></script>
    <script src="/adapter.js"></script><script>window.mrnRecaptchaFormLoader.init({api:'https://www.google.com/recaptcha/api.js?render=fixture-site-key',siteKey:'fixture-site-key',formId:687,error:'Fixture CAPTCHA could not load. Please retry.'});</script></body></html>`;
}

(async () => {
  fs.mkdirSync(output, {recursive:true});
  const requests = [];
  const tokenRequests = [];
  const server = http.createServer((request,response) => {
    const url = new URL(request.url, 'http://fixture.invalid');
    if (request.method === 'POST' && url.pathname === '/fixture-transport') {
      let body=''; request.on('data', chunk=>body+=chunk); request.on('end',()=>{
        if(new URLSearchParams(body).get('action')==='wpforms_get_token') {
          tokenRequests.push(body);
          response.writeHead(200, {'Content-Type':'application/json'});
          response.end(JSON.stringify({success:true,data:{token:'fixture-native-nonce',time_token:String(Date.now())}}));
          return;
        }
        requests.push(body);
        fs.writeFileSync(path.join(output,'transport-debug.json'),JSON.stringify(requests,null,2)+'\n');
        response.writeHead(200, {'Content-Type':'application/json'});
        response.end(JSON.stringify({success:false,data:{errors:{general:'Fixture stopped after observing native submission.'}}}));
      }); return;
    }
    if (files.has(url.pathname)) { response.writeHead(200, {'Content-Type':'application/javascript'});response.end(files.get(url.pathname));return; }
    const version = url.pathname.slice(1);
    if (versions.includes(version)) {response.writeHead(200, {'Content-Type':'text/html'});response.end(html(version,url.searchParams.get('mode') || 'valid'));return;}
    response.writeHead(404); response.end('Fixture path unavailable');
  });
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  const base='http://127.0.0.1:'+server.address().port;
  const browser=await chromium.launch({headless:true});
  const checks=[]; const errors=[]; const escapedRequests=[];
  const ok=(value,label)=>{assert(value,label);checks.push(label);process.stdout.write('PASS '+label+'\n');};
  try {
    for (const version of versions) {
      for (const viewport of [{width:1280,height:900},{width:390,height:844}]) {
        const context=await browser.newContext({viewport});
        await context.route('**/*',route=>{
          const url=route.request().url();
          if(url.startsWith(base+'/'))return route.continue();
          if(url.startsWith('https://www.google.com/recaptcha/api.js?render=fixture-site-key'))return route.fulfill({contentType:'application/javascript',body:google});
          escapedRequests.push(url);return route.abort();
        });
        const page=await context.newPage();page.on('pageerror',error=>errors.push(error.message));
        const suffix=version+' at '+viewport.width;
        const initial=requests.length;
        await page.goto(base+'/'+version+'?mode=hold');
        await page.waitForFunction(()=>!!window.jQuery('#wpforms-form-687').data('validator'));
        await page.getByRole('button',{name:'Send Message',exact:true}).click();
        ok(requests.length===initial,'Required-field validation prevents transport '+suffix);
        ok(await page.evaluate(()=>window.fixtureExecutions===0),'Invalid form never requests a token '+suffix);
        await page.getByLabel('Name',{exact:true}).fill('Fixture');await page.getByLabel('Email',{exact:true}).fill('fixture@example.test');
        const count=requests.length;
        await page.getByRole('button',{name:'Send Message',exact:true}).click();
        ok(requests.length===count,'Early valid submit waits for Google readiness '+suffix);
        await page.waitForFunction(()=>typeof window.fixtureReleaseApi==='function');
        await page.evaluate(()=>window.fixtureReleaseApi());
        await page.waitForFunction(()=>!!document.querySelector('.wpforms-error-container'));
        ok(requests.length===count+1,'Native AJAX continuation runs once '+suffix);
        ok(requests.at(-1).includes('fresh-fixture-1'),'Native transport carries the fresh token '+suffix);
        const previous=requests.length;
        await page.goto(base+'/'+version+'?mode=empty');await page.waitForFunction(()=>!!window.jQuery('#wpforms-form-687').data('validator'));
        await page.getByLabel('Name',{exact:true}).fill('Retry fixture');await page.getByLabel('Email',{exact:true}).fill('fixture@example.test');
        await page.getByRole('button',{name:'Send Message',exact:true}).click();
        await page.locator('.mrn-recaptcha-load-error[role="alert"]').waitFor({state:'visible'});
        ok(requests.length===previous,'Empty token cannot continue '+suffix);
        ok(await page.getByRole('button',{name:'Send Message',exact:true}).isEnabled(),'Failure restores native submit button '+suffix);
        ok(await page.getByLabel('Name',{exact:true}).inputValue()==='Retry fixture','Failure preserves entered fields '+suffix);
        ok(await page.locator('[name="wpforms[recaptcha]"]').inputValue()==='','Failure clears token '+suffix);
        await page.evaluate(()=>{window.fixtureMode='valid';});
        await page.getByRole('button',{name:'Send Message',exact:true}).click();
        await page.waitForFunction(()=>!!document.querySelector('.wpforms-error-container'));
        ok(requests.length===previous+1,'Retry reaches native transport exactly once '+suffix);
        ok(requests.at(-1).includes('fresh-fixture-2'),'Retry uses a newly requested token '+suffix);
        await context.close();
      }
    }
    ok(errors.length===0,'No uncaught browser errors');
    ok(escapedRequests.length===0,'No external transport or telemetry escaped the fixture');
    const result={status:'pass',assertions:checks.length,checks,versions,loader_sha256:sha(loader),vendor_assets:Object.fromEntries(versions.map(v=>[v,manifest.versions[v].hashes])),google:'controlled fixture API; not genuine Google scoring',transport:'loopback fixture only; no database, email or production submissions',native_nonce_refreshes:tokenRequests.length,page_errors:errors,external_requests:escapedRequests};
    fs.writeFileSync(path.join(output,'wpforms-browser-compat.json'),JSON.stringify(result,null,2)+'\n');
  } finally {await browser.close();await new Promise(resolve=>server.close(resolve));}
})().catch(error=>{process.stderr.write(error.stack+'\n');process.exit(1);});
