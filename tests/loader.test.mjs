import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
const source = readFileSync(new URL('../assets/wpforms-form-loader.js', import.meta.url), 'utf8');
const flush = async () => { for (let i = 0; i < 12; i++) await Promise.resolve(); };

function fixture({observer = true, existing = false} = {}) {
  const state = {scripts: [], calls: [], events: [], timers: new Map(), restored: 0, handlers: {}};
  const token = {value: 'stale'};
  const form = {isConnected: true, error: null, addEventListener: (n, cb) => state.handlers[n] = cb,
    querySelector: () => form.error, querySelectorAll: () => [token],
    appendChild: el => { form.error = el; el.remove = () => form.error = null; }};
  const document = {getElementById: id => id === 'wpforms-recaptcha-js' ? {nonce: 'test-nonce'} : form,
    createElement: () => ({setAttribute(k, v) { this[k] = v; }, remove() { this.removed = true; }}),
    head: {appendChild: el => state.scripts.push(el)}, dispatchEvent: e => state.events.push(e.type)};
  let serial = 0;
  const window = {setTimeout: cb => {state.timers.set(++serial, cb); return serial;}, clearTimeout: id => state.timers.delete(id),
    wpforms: {restoreSubmitButton: () => state.restored++}, jQuery: () => ({closest: () => ({})})};
  if (observer) window.IntersectionObserver = class { constructor(cb, options) {state.intersect = cb; state.options = options;} observe() {} disconnect() {state.disconnected = true;} };
  const api = {ready: cb => cb(), execute: (key, options) => {state.calls.push({key, options}); return Promise.resolve('fresh-' + state.calls.length);}};
  if (existing) window.grecaptcha = api;
  vm.runInNewContext(source, {window, document, CustomEvent: class {constructor(type) {this.type = type;}}});
  window.mrnRecaptchaFormLoader.init({formId: 68, siteKey: 'public-test-key', api: 'https://www.google.com/recaptcha/api.js?render=public-test-key', error: 'Please retry'});
  return {state, token, form, window, api, complete: async () => {window.grecaptcha = api; state.scripts.at(-1)?.onload(); await flush();}};
}

test('no initial Google request; near-form load is shared with focus and pointer intent', async () => {
  const f = fixture();
  assert.equal(f.state.scripts.length, 0);
  assert.equal(f.state.options.rootMargin, '1000px 0px');
  f.state.intersect([{isIntersecting: true}]); f.state.handlers.focusin(); f.state.handlers.pointerdown();
  assert.equal(f.state.scripts.length, 1);
  assert.equal(f.state.scripts[0].nonce, 'test-nonce');
  await f.complete();
  assert.deepEqual(f.state.events, ['wpformsRecaptchaLoaded']);
  assert.equal(f.state.calls.length, 0, 'warming must not mint a token');
});

test('early submit waits for API, gets a fresh wpforms token, and continues exactly once', async () => {
  const f = fixture(); let submitted = 0;
  f.window.wpformsRecaptchaV3Execute(() => submitted++);
  f.window.wpformsRecaptchaV3Execute(() => submitted++);
  await flush(); assert.equal(submitted, 0);
  await f.complete();
  assert.equal(submitted, 1); assert.equal(f.token.value, 'fresh-1');
  assert.equal(f.state.calls[0].options.action, 'wpforms');
  f.window.wpformsRecaptchaV3Execute(() => submitted++); await flush();
  assert.equal(submitted, 2); assert.equal(f.token.value, 'fresh-2');
});

test('API failure blocks continuation, clears stale tokens, restores button, and allows retry', async () => {
  const f = fixture(); let submitted = 0;
  f.window.wpformsRecaptchaV3Execute(() => submitted++);
  f.state.scripts[0].onerror(); await flush();
  assert.equal(submitted, 0); assert.equal(f.token.value, '');
  assert.equal(f.form.error.role, 'alert'); assert.equal(f.state.restored, 1);
  f.window.wpformsRecaptchaV3Execute(() => submitted++); await f.complete();
  assert.equal(submitted, 1); assert.equal(f.form.error, null);
});

test('load timeout and late arrival cannot submit a timed-out attempt', async () => {
  const f = fixture(); let submitted = 0;
  f.window.wpformsRecaptchaV3Execute(() => submitted++);
  [...f.state.timers.values()][0](); await flush();
  await f.complete(); assert.equal(submitted, 0); assert.equal(f.state.restored, 1);
  f.window.wpformsRecaptchaV3Execute(() => submitted++); await flush();
  assert.equal(submitted, 1);
});

for (const mode of ['reject', 'empty', 'timeout']) test('token ' + mode + ' cannot submit; retry is fresh', async () => {
  const f = fixture({existing: true}); let submitted = 0;
  f.api.execute = () => mode === 'reject' ? Promise.reject(new Error('Google error')) : mode === 'empty' ? Promise.resolve('') : new Promise(() => {});
  f.window.wpformsRecaptchaV3Execute(() => submitted++); await flush();
  if (mode === 'timeout') { [...f.state.timers.values()][0](); await flush(); }
  assert.equal(submitted, 0); assert.equal(f.state.restored, 1); assert.equal(f.token.value, '');
  f.api.execute = () => Promise.resolve('retry-token');
  f.window.wpformsRecaptchaV3Execute(() => submitted++); await flush();
  assert.equal(submitted, 1); assert.equal(f.token.value, 'retry-token');
});

test('no observer uses immediate loading; preexisting Google API is reused', async () => {
  const eager = fixture({observer: false}); assert.equal(eager.state.scripts.length, 1); await eager.complete();
  const f = fixture({existing: true}); f.state.handlers.focusin(); await flush();
  assert.equal(f.state.scripts.length, 0); assert.equal(f.state.events.length, 1);
});
