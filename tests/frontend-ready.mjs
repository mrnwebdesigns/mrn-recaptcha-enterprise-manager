// Exercise the generated browser output, including WPForms' original inline code.
// Usage: node tests/frontend-ready.mjs /path/to/candidate-page.html
import fs from 'node:fs/promises';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const html = await fs.readFile(process.argv[2], 'utf8');
function script(id) {
  const match = html.match(new RegExp(`<script\\b[^>]*\\bid=['"]${id}['"][^>]*>([\\s\\S]*?)</script>`));
  assert(match, `Missing ${id}`);
  return match[1];
}
for (const mode of ['slow', 'already-loaded', 'failed']) {
  const fields = [{value: ''}, {value: ''}];
  const events = [];
  const calls = [];
  let callbackCount = 0;
  const context = vm.createContext({
    document: {getElementsByName: () => fields, dispatchEvent: e => events.push(e.type), createEvent: () => ({initEvent(type) {this.type = type;}, initCustomEvent(type) {this.type = type;}})},
    CustomEvent: class {constructor(type) {this.type = type;}},
  });
  context.window = context;
  const ready = callback => callback();
  const execute = (key, options) => { calls.push({key, options}); return Promise.resolve('test-token'); };
  if (mode === 'already-loaded') context.grecaptcha = {ready, execute, existing: true};
  vm.runInContext(script('mrn-recaptcha-ready'), context);
  vm.runInContext(script('wpforms-recaptcha-js-after'), context);
  vm.runInContext(script('mrn-recaptcha-submit-ready'), context);
  context.wpformsRecaptchaV3Execute(() => callbackCount++);
  if (mode !== 'already-loaded') {
    assert.equal(calls.length, 0, 'An early submit must not execute an unloaded API');
    assert.equal(callbackCount, 0, 'A pending/failed loader must not release submission');
    assert(fields.every(field => field.value === ''));
  }
  if (mode === 'failed') continue;
  if (mode === 'slow') {
    const pending = context.___grecaptcha_cfg.fns;
    context.grecaptcha = {ready, execute};
    pending.forEach(callback => callback());
  } else {
    assert.equal(context.grecaptcha.ready, ready, 'Preserve the real API when already loaded');
    assert.equal(context.grecaptcha.existing, true);
  }
  await Promise.resolve();
  assert.equal(callbackCount, 1);
  assert.equal(calls.length, 1);
  assert.equal(calls[0].options.action, 'wpforms');
  assert(fields.every(field => field.value === 'test-token'));
  assert.deepEqual(events, ['wpformsRecaptchaLoaded']);
}
console.log('PASS: slow, already-loaded and failed Google loader states preserve token-before-submit behavior.');
