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

	// Cookie the PHP-side proxy stamps after a successful session-create
	// response. See AuthSessionProxyController::INITIATOR_COOKIE.
	// Present + matching → URL-param auto-complete runs (same-device flow).
	// Absent or mismatched → params are scrubbed without auto-completing,
	// so a cross-device QR scan doesn't accidentally sign in the phone
	// that just approved a desktop-initiated session.
	var INITIATOR_COOKIE = 'qrauth_psl_initiator';

	function readCookie(name) {
		var target = name + '=';
		var parts = (document.cookie || '').split(';');
		for (var i = 0; i < parts.length; i++) {
			var c = parts[i].replace(/^\s+/, '');
			if (c.indexOf(target) === 0) {
				return c.substring(target.length);
			}
		}
		return '';
	}

	function clearInitiatorCookie() {
		document.cookie =
			INITIATOR_COOKIE +
			'=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; samesite=lax' +
			(window.location.protocol === 'https:' ? '; secure' : '');
	}

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
	// The URL is scrubbed via history.replaceState BEFORE any POST so a
	// page refresh can't replay a consumed signature (the server would
	// reject it anyway, but the URL bar should look clean immediately).
	//
	// The initiator cookie gates whether we auto-complete sign-in: the
	// cookie is only set on browsers that called POST /auth-sessions for
	// this sessionId, so its presence distinguishes the same-device flow
	// (browser A initiated AND browser A approves + redirects) from the
	// cross-device QR scan (browser A initiated, browser B approves and
	// ends up here via the redirect-uri). In the cross-device case the
	// browser B user didn't ask to sign in to this site — signing them in
	// behind their back is the UX smell we're avoiding.
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

		var initiatorSessionId = readCookie(INITIATOR_COOKIE);
		if (initiatorSessionId !== sessionId) {
			// Cross-device (or cookie expired / session changed). Don't
			// auto-authenticate this browser. The URL is already scrubbed
			// so a refresh is clean.
			return;
		}

		// Same device that initiated the session. Consume the cookie and
		// complete sign-in via the standard event-path verify() call.
		clearInitiatorCookie();

		// If a widget rendered on this page, use it for error display and
		// qrauth:error dispatch. Otherwise verify() falls back to document.body.
		var widget = document.querySelector('qrauth-login');
		verify(widget, sessionId, signature);
	}

	document.addEventListener('qrauth:authenticated', onAuthenticated);
	pickUpUrlParams();
}());
