/* Enterprise tokens belong to one form submission, never to WPForms. */
(function () {
	'use strict';
	if (window.mrnCommentProtectionLoaded) return;
	window.mrnCommentProtectionLoaded = true;
	const states = new WeakMap();
	let loading;

	function deadline(promise) {
		let timer;
		return Promise.race([
			promise,
			new Promise((resolve, reject) => { timer = setTimeout(() => reject(new Error('timeout')), 12000); })
		]).finally(() => clearTimeout(timer));
	}

	function enterprise(key) {
		if (!loading) {
			loading = deadline(new Promise((resolve, reject) => {
				const ready = () => {
					if (window.grecaptcha && window.grecaptcha.enterprise) {
						window.grecaptcha.enterprise.ready(() => resolve(window.grecaptcha.enterprise));
					} else reject(new Error('unavailable'));
				};
				if (window.grecaptcha && window.grecaptcha.enterprise) return ready();
				// Share an existing Enterprise loader; leave legacy api.js and WPForms alone.
				let script = Array.from(document.scripts).find((item) => {
					try {
						const url = new URL(item.src, document.baseURI);
						return ['www.google.com', 'www.recaptcha.net'].includes(url.hostname) && url.pathname === '/recaptcha/enterprise.js';
					} catch (error) { return false; }
				});
				if (!script) {
					script = document.createElement('script');
					script.src = 'https://www.google.com/recaptcha/enterprise.js?render=' + encodeURIComponent(key);
					script.async = true;
					script.dataset.mrnCommentLoader = '1';
					script.addEventListener('load', ready, {once: true});
					script.addEventListener('error', () => { script.remove(); reject(new Error('load')); }, {once: true});
					document.head.appendChild(script);
				} else {
					script.addEventListener('load', ready, {once: true});
					script.addEventListener('error', () => reject(new Error('load')), {once: true});
				}
			})).catch((error) => { loading = null; throw error; });
		}
		return loading;
	}

	// Delegation supports multiple forms, moved reply forms and inserted fragments.
	document.addEventListener('submit', async (event) => {
		const form = event.target;
		const field = form.querySelector('.mrn-recaptcha-comment');
		if (!field) return;
		let state = states.get(form);
		if (!state) { state = {busy: false, pass: false}; states.set(form, state); }
		if (state.pass) { state.pass = false; return; }
		event.preventDefault();
		event.stopImmediatePropagation();
		if (state.busy || !form.reportValidity()) return;
		const token = field.querySelector('input[name="mrn_recaptcha_token"]');
		const status = field.querySelector('.mrn-recaptcha-status');
		const submitter = event.submitter;
		token.value = '';
		state.busy = true;
		form.setAttribute('aria-busy', 'true');
		status.textContent = field.dataset.wait;
		try {
			const api = await enterprise(field.dataset.siteKey);
			const result = await deadline(api.execute(field.dataset.siteKey, {action: field.dataset.action}));
			if (typeof result !== 'string' || !result) throw new Error('empty');
			token.value = result;
			// A synchronously resolved provider promise can still be inside the native
			// submission algorithm. Leave that turn before dispatching another submit.
			await new Promise(resolve => setTimeout(resolve, 0));
			state.pass = true;
			// Re-run native validation and submit listeners with the original button.
			if (submitter && submitter.form === form) form.requestSubmit(submitter);
			else form.requestSubmit();
			state.pass = false;
			status.textContent = '';
		} catch (error) {
			token.value = '';
			status.textContent = field.dataset.error;
			status.focus();
		} finally {
			state.busy = false;
			form.removeAttribute('aria-busy');
			// Native submission has serialized the token. An AJAX retry gets a fresh one.
			setTimeout(() => { token.value = ''; }, 0);
		}
	}, true);
	window.addEventListener('pageshow', () => {
		document.querySelectorAll('.mrn-recaptcha-comment input').forEach((input) => { input.value = ''; });
	});
})();
