/*!
 * qrauth-psl adapter — bridges the <qrauth-login> widget to the plugin's
 * REST route. Two entry paths:
 *
 *   1. `qrauth:authenticated` custom event from the widget (desktop /
 *      scan-with-another-device flow — polling picks up APPROVED, the
 *      widget emits the event, we POST { sessionId, signature } to
 *      /wp-json/qrauth-psl/v1/verify).
 *   2. Landing-page URL params (mobile same-device flow — the qrauth.io
 *      hosted approval page appends
 *      `?qrauth_session_id=...&qrauth_signature=...` to the configured
 *      `redirect-uri` after the user taps Approve, and we pick those up
 *      on load). This matches the pattern documented in
 *      vqr/packages/docs/guide/web-components.md §"URL-param callback".
 *
 * Both paths POST to the same REST route, which sets the WP auth cookie
 * and returns a redirect URL. Dependency-free; runs on any browser that
 * supports custom elements v1 (same floor as @qrauth/web-components).
 *
 * Source-as-shipped — no bundler. Do not add third-party imports here.
 */
(function () {
	'use strict';

	var config = window.qrauthPsl || {};
	var verifyUrl = (config.restUrl || '/wp-json/') + 'qrauth-psl/v1/verify';

	// Query-param names appended by qrauth.io's hosted approval page.
	// Kept as constants so a future rename upstream is a one-line fix.
	var PARAM_SESSION_ID = 'qrauth_session_id';
	var PARAM_SIGNATURE = 'qrauth_signature';

	function showError(widget, message) {
		var host = (widget && widget.parentElement) || document.body;
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

		if (widget && typeof widget.dispatchEvent === 'function') {
			widget.dispatchEvent(new CustomEvent('qrauth:error', {
				bubbles: true,
				composed: true,
				detail: { message: message }
			}));
		}
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

	// Mobile same-device landing handler. When qrauth.io redirects the
	// user back after Approve, the URL carries the same sessionId +
	// signature the widget's poll loop would have received — except the
	// original tab was probably suspended while the user was on the
	// qrauth.io tab, so the event path never fires. Picking the params up
	// here makes the flow independent of any still-alive polling.
	//
	// The URL is scrubbed via history.replaceState BEFORE the POST so a
	// page refresh can't replay a consumed signature (the server would
	// reject it anyway, but the URL bar should look clean immediately).
	function pickUpUrlParams() {
		if (typeof URL !== 'function' || typeof window.history === 'undefined') {
			return;
		}

		var url = new URL(window.location.href);
		var sessionId = url.searchParams.get(PARAM_SESSION_ID);
		var signature = url.searchParams.get(PARAM_SIGNATURE);
		if (!sessionId || !signature) {
			return;
		}

		url.searchParams.delete(PARAM_SESSION_ID);
		url.searchParams.delete(PARAM_SIGNATURE);
		window.history.replaceState({}, '', url.pathname + url.search + url.hash);

		// If a widget rendered on this page, use it for error display and
		// qrauth:error dispatch. Otherwise verify() falls back to document.body.
		var widget = document.querySelector('qrauth-login');
		verify(widget, sessionId, signature);
	}

	document.addEventListener('qrauth:authenticated', onAuthenticated);
	pickUpUrlParams();
}());
