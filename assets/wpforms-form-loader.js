/* Opt-in WPForms 2.0.1.1/2.0.2.1 classic v3 adapter. Server validation is unchanged. */
(function (window, document) {
	'use strict';
	window.mrnRecaptchaFormLoader = {
		init: function (config) {
			var form = document.getElementById('wpforms-form-' + config.formId);
			var pendingLoad = null;
			var pendingSubmit = false;
			var observer;
			var apiScript;
			var readyAnnounced = false;
			var bootstrap = document.getElementById('wpforms-recaptcha-js');
			var nonce = bootstrap && bootstrap.nonce;

			function bounded(promise, milliseconds) {
				return new Promise(function (resolve, reject) {
					var timer = window.setTimeout(function () { reject(new Error('CAPTCHA timeout')); }, milliseconds);
					promise.then(function (value) { window.clearTimeout(timer); resolve(value); }, function (error) { window.clearTimeout(timer); reject(error); });
				});
			}

			function load() {
				if (pendingLoad) { return pendingLoad; }
				var loading = new Promise(function (resolve, reject) {
					function ready() {
						if (!window.grecaptcha || typeof window.grecaptcha.ready !== 'function') { reject(new Error('CAPTCHA unavailable')); return; }
						window.grecaptcha.ready(function () {
							if (typeof window.grecaptcha.execute !== 'function') { reject(new Error('CAPTCHA unavailable')); return; }
							resolve();
						});
					}
					if (window.grecaptcha && typeof window.grecaptcha.execute === 'function') { ready(); return; }
					apiScript = document.createElement('script');
					apiScript.src = config.api;
					apiScript.async = true;
					if (nonce) { apiScript.nonce = nonce; }
					apiScript.onload = ready;
					apiScript.onerror = function () { reject(new Error('CAPTCHA network failure')); };
					document.head.appendChild(apiScript);
				});
				pendingLoad = bounded(loading, 15000).then(function () {
					if (observer) { observer.disconnect(); }
					if (!readyAnnounced) {
						readyAnnounced = true;
						document.dispatchEvent(new CustomEvent('wpformsRecaptchaLoaded', { bubbles: true }));
					}
				}, function (error) {
					pendingLoad = null;
					if (apiScript) { apiScript.remove(); apiScript = null; }
					throw error;
				});
				return pendingLoad;
			}

			function clearError() {
				if (!form) { return; }
				var error = form.querySelector('.mrn-recaptcha-load-error');
				if (error) { error.remove(); }
			}

			function fail() {
				pendingSubmit = false;
				if (!form) { return; }
				form.querySelectorAll('[name="wpforms[recaptcha]"]').forEach(function (field) { field.value = ''; });
				clearError();
				var error = document.createElement('p');
				error.className = 'wpforms-error mrn-recaptcha-load-error';
				error.setAttribute('role', 'alert');
				error.textContent = config.error;
				form.appendChild(error);
				if (window.wpforms && typeof window.wpforms.restoreSubmitButton === 'function' && window.jQuery) {
					var $form = window.jQuery(form);
					window.wpforms.restoreSubmitButton($form, $form.closest('.wpforms-container'));
				}
			}

			// WPForms calls this only after its own field validation. Never call the
			// native submit continuation without a new, nonempty Google token.
			window.wpformsRecaptchaV3Execute = function (callback) {
				if (pendingSubmit) { return; }
				pendingSubmit = true;
				clearError();
				load().then(function () {
					return bounded(window.grecaptcha.execute(config.siteKey, { action: 'wpforms' }), 15000);
				}).then(function (token) {
					if (typeof token !== 'string' || !token || !form || !form.isConnected) { throw new Error('CAPTCHA token unavailable'); }
					var fields = form.querySelectorAll('[name="wpforms[recaptcha]"]');
					if (!fields.length) { throw new Error('CAPTCHA field unavailable'); }
					fields.forEach(function (field) { field.value = token; });
					pendingSubmit = false;
					if (typeof callback === 'function') { callback(); }
				}).catch(fail);
			};

			function warm() { load().catch(function () { /* A submission retries and reports failures accessibly. */ }); }
			if (!form) { warm(); return; }
			form.addEventListener('focusin', warm);
			form.addEventListener('pointerdown', warm, { passive: true });
			if ('IntersectionObserver' in window) {
				observer = new window.IntersectionObserver(function (entries) {
					if (entries.some(function (entry) { return entry.isIntersecting; })) { warm(); }
				}, { rootMargin: '1000px 0px' });
				observer.observe(form);
			} else { warm(); }
		}
	};
})(window, document);
