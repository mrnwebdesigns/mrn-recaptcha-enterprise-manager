/* Exact installed package, real WordPress; Google intercepted, mail blocked. */
const assert = require('node:assert/strict');
const {writeFileSync} = require('node:fs');
const path = require('node:path');
const {chromium} = require('playwright');
const AxeBuilder = require('@axe-core/playwright').default;
const base = 'http://127.0.0.1:8765';
const reports = process.env.MRN_RECAPTCHA_REPORT_DIR;
if (!reports) throw new Error('An explicit qualification report directory is required');
(async () => {
	const browser = await chromium.launch({headless: true});
	const checks = [];
	const ok = (value, label) => { assert(value, label); checks.push(label); process.stdout.write('PASS: ' + label + '\n'); };
	const context = await browser.newContext();
	let enterpriseLoads = 0;
	await context.route('**/*', async route => {
		const url = route.request().url();
		if (url.startsWith(base + '/')) return route.continue();
		if (url.startsWith('https://www.google.com/recaptcha/enterprise.js')) {
			enterpriseLoads++;
			return route.fulfill({contentType: 'application/javascript', body: `window.grecaptcha = window.grecaptcha || {}; window.grecaptcha.enterprise = {ready: fn => fn(), execute: async () => 'valid:' + crypto.randomUUID()};`});
		}
		if (url.startsWith('https://www.google.com/recaptcha/api.js')) {
			return route.fulfill({contentType: 'application/javascript', body: `window.grecaptcha = window.grecaptcha || {}; window.grecaptcha.ready = fn => fn(); window.grecaptcha.execute = async () => 'wpforms-fixture-token'; const callback = new URL(document.currentScript.src).searchParams.get('onload'); if (callback) { const wait = () => typeof window[callback] === 'function' ? window[callback]() : setTimeout(wait, 10); wait(); }`});
		}
		return route.abort();
	});
	const page = await context.newPage();
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	for (const viewport of [{width: 1280, height: 900}, {width: 390, height: 844}]) {
		await page.setViewportSize(viewport);
		for (const target of ['', 'qa/page/', 'qa/attachment/', 'qa/custom/']) {
			const before = enterpriseLoads;
			await page.goto(base + '/' + target);
			ok(await page.locator('form .mrn-recaptcha-comment[data-action="mrn_blog_comment"]').count() === 1, target + ' has one core comment protection field at ' + viewport.width);
			ok(await page.locator('script[src*="comment-protection."]').count() === 1, target + ' uses one packaged immutable script');
			ok(enterpriseLoads === before, target + ' does not load Google before submission');
			const axe = await new AxeBuilder({page}).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
			ok(axe.violations.length === 0, target + ' passes WCAG A/AA at ' + viewport.width);
			ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), target + ' fits the viewport');
		}
	}
	await page.goto(base + '/qa/unrelated/');
	ok(await page.locator('script[src*="comment-protection."]').count() === 0, 'Unrelated page has no CAPTCHA script');
	await page.goto(base + '/qa/no-woocommerce-product/');
	ok(await page.locator('.mrn-recaptcha-comment').count() === 0, 'Review setting is inert without WooCommerce');
	await page.goto(base + '/qa/subscriber/');
	ok(await page.locator('.mrn-recaptcha-comment').count() === 1, 'Authenticated subscriber comment form remains protected');
	await page.goto(base + '/qa/admin/');
	ok(await page.locator('.mrn-recaptcha-comment').count() === 0, 'Administrator form remains exempt');
	if (process.env.MRN_RECAPTCHA_WPFORMS_EXPECTED === '1') {
		await page.goto(base + '/qa/wpforms/');
		ok(await page.locator('.wpforms-form').count() === 1, 'Native WPForms form renders alongside ordinary comments without WooCommerce');
		ok(await page.locator('#commentform .mrn-recaptcha-comment').count() === 1, 'Comments on the WPForms page retain independent protection');
		writeFileSync(path.join(reports, 'wpforms-page.html'), await page.content());
		await page.waitForFunction(() => typeof window.grecaptcha?.execute === 'function');
		for (const width of [1280, 390]) {
			await page.setViewportSize({width, height: 900});
			const axe = await new AxeBuilder({page}).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
			ok(axe.violations.length === 0, 'Native WPForms and comments pass WCAG A/AA at ' + width);
		}
		await page.locator('#comment').fill('Coexistence fixture');
		await page.locator('#author').fill('Fixture'); await page.locator('#email').fill('fixture@example.test');
		await page.evaluate(() => document.querySelector('#commentform').addEventListener('submit', event => { event.preventDefault(); window.coexistenceToken = new FormData(event.target).get('mrn_recaptcha_token'); }));
		await page.locator('#submit').click();
		await page.waitForFunction(() => !!window.coexistenceToken);
		ok(await page.evaluate(() => window.coexistenceToken.startsWith('valid:') && typeof window.grecaptcha.execute === 'function' && typeof window.grecaptcha.enterprise.execute === 'function'), 'Comment token generation preserves WPForms legacy Google namespace');
	}
	await page.goto(base + '/');
	await page.locator('#comment').fill('No WooCommerce browser submission ' + Date.now());
	await page.locator('#author').fill('Fixture');
	await page.locator('#email').fill('fixture@example.test');
	const [posted] = await Promise.all([page.waitForResponse(r => r.url().endsWith('/wp-comments-post.php')), page.locator('#submit').click()]);
	ok(posted.status() === 302, 'Native WordPress submission accepts browser-generated token');
	await page.waitForLoadState('load');
	ok(enterpriseLoads > 0, 'Google loader runs only when submitting');
	await page.goto(base + '/qa/multiple/');
	await page.evaluate(() => {
		window.tokens = [];
		document.querySelectorAll('form').forEach(form => {
			form.querySelector('textarea').value = 'Multiple form fixture';
			form.querySelector('[name="author"]').value = 'Fixture';
			form.querySelector('[name="email"]').value = 'fixture@example.test';
			form.addEventListener('submit', e => { e.preventDefault(); window.tokens.push(new FormData(form).get('mrn_recaptcha_token')); });
		});
	});
	await page.locator('#submit').click();
	await page.waitForFunction(() => window.tokens.length === 1);
	await page.locator('#second-submit').click();
	await page.waitForFunction(() => window.tokens.length === 2);
	ok(await page.evaluate(() => window.tokens[0] && window.tokens[1] && window.tokens[0] !== window.tokens[1]), 'Multiple core forms get independent fresh tokens');
	ok(await page.locator('script[src*="enterprise.js"]').count() === 1, 'Multiple core forms share one Google loader');
	const offline = await browser.newContext();
	await offline.route('**/*', route => route.request().url().startsWith(base + '/') ? route.continue() : route.abort());
	const failed = await offline.newPage();
	failed.on('pageerror', error => errors.push(error.message));
	await failed.goto(base + '/');
	await failed.locator('#comment').fill('Retain text when provider is blocked');
	await failed.locator('#author').fill('Fixture');
	await failed.locator('#email').fill('fixture@example.test');
	await failed.locator('#submit').click();
	await failed.waitForFunction(() => document.querySelector('.mrn-recaptcha-status').textContent.includes('could not verify'));
	ok(await failed.locator('#comment').inputValue() === 'Retain text when provider is blocked', 'Provider failure preserves entered text');
	ok(await failed.locator('.mrn-recaptcha-status').evaluate(el => el === document.activeElement), 'Provider failure focuses the accessible live status');
	ok(!(await failed.locator('#submit').isDisabled()), 'Provider failure allows retry');
	const nojs = await browser.newContext({javaScriptEnabled: false});
	await nojs.route('**/*', route => route.request().url().startsWith(base + '/') ? route.continue() : route.abort());
	const disabled = await nojs.newPage();
	await disabled.goto(base + '/');
	ok((await disabled.locator('noscript').innerText()).includes('JavaScript is required'), 'No-JavaScript form explains verification requirement');
	await disabled.locator('#comment').fill('No-JavaScript fixture');
	await disabled.locator('#author').fill('Fixture');
	await disabled.locator('#email').fill('fixture@example.test');
	const [rejection] = await Promise.all([disabled.waitForNavigation(), disabled.locator('#submit').click()]);
	ok(rejection.status() === 403 && (await disabled.locator('body').innerText()).includes('Your submission was not saved'), 'No-JavaScript native submission is rejected before saving');
	await page.goto(base + '/');
	await page.locator('#comment').fill('Native submit bypass');
	await page.locator('#author').fill('Fixture');
	await page.locator('#email').fill('fixture@example.test');
	const [bypass] = await Promise.all([page.waitForNavigation(), page.evaluate(() => HTMLFormElement.prototype.submit.call(document.querySelector('form')))]);
	ok(bypass.status() === 403, 'Direct form.submit cannot bypass the server check');
	ok(errors.length === 0, 'No browser JavaScript errors in the selected optional-plugin scenario');
	await page.screenshot({path: path.join(reports, 'server-rejection.png'), fullPage: true});
	await context.close();
	await offline.close();
	await nojs.close();
	await browser.close();
	writeFileSync(path.join(reports, 'browser.json'), JSON.stringify({status: 'pass', assertions: checks.length, checks, pageErrors: errors, google: 'intercepted'}, null, 2) + '\n');
})().catch(error => { process.stderr.write(error.stack + '\n'); process.exit(1); });
