/*!
 * qrauth-psl adapter — bridges the <qrauth-login> widget to the plugin's
 * REST route. Listens for the widget's `qrauth:authenticated` event,
 * forwards { sessionId, signature } to /wp-json/qrauth-psl/v1/verify,
 * and redirects on success. Dependency-free; runs on any browser that
 * supports custom elements v1 (same floor as @qrauth/web-components).
 *
 * Source-as-shipped — no bundler. Do not add third-party imports here.
 */
(function () {
	'use strict';

	var config = window.qrauthPsl || {};
	var verifyUrl = (config.restUrl || '/wp-json/') + 'qrauth-psl/v1/verify';

	function showError(widget, message) {
		var host = widget.parentElement || widget;
		var prev = host.querySelector('[data-qrauth-psl-error]');
		if (prev) {
			prev.remove();
		}

		var p = document.createElement('p');
		p.setAttribute('data-qrauth-psl-error', '');
		p.setAttribute('role', 'alert');
		p.style.color = '#b32d2e';
		p.style.margin = '0.5em 0';
		p.textContent = message;
		host.appendChild(p);

		widget.dispatchEvent(new CustomEvent('qrauth:error', {
			bubbles: true,
			composed: true,
			detail: { message: message }
		}));
	}

	function verify(widget, sessionId, signature) {
		return fetch(verifyUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'Accept': 'application/json',
				'X-WP-Nonce': config.nonce || ''
			},
			body: JSON.stringify({ sessionId: sessionId, signature: signature })
		}).then(function (res) {
			if (!res.ok) {
				return res.json().catch(function () { return {}; }).then(function () {
					showError(widget, 'Sign-in failed. Please try again.');
				});
			}
			return res.json().then(function (data) {
				if (data && data.ok === true && typeof data.redirect === 'string' && data.redirect.length > 0) {
					window.location.assign(data.redirect);
					return;
				}
				showError(widget, 'Sign-in failed. Please try again.');
			});
		}).catch(function () {
			showError(widget, 'Network error. Please try again.');
		});
	}

	function onAuthenticated(event) {
		var detail = event.detail || {};
		if (!detail.sessionId || !detail.signature) {
			return;
		}
		var widget = event.target;
		if (!widget || typeof widget.dispatchEvent !== 'function') {
			return;
		}
		verify(widget, detail.sessionId, detail.signature);
	}

	document.addEventListener('qrauth:authenticated', onAuthenticated);
}());
