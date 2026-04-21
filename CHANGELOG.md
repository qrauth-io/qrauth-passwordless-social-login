# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.10] — 2026-04-22

### Changed

- **Translation scaffolding regenerated.** `languages/qrauth-passwordless-social-login.pot` rebuilt from current source via `wp i18n make-pot` inside wp-env. String count grew from 41 (0.1.0 baseline) to 56, picking up every user-facing string added across 0.1.1 → 0.1.9 — Client Secret field + help text, Tenant URL description, mobile sign-in setup callout, per-surface enable switches for Anywhere via shortcode and the two WooCommerce entries, and the multilingual allowlist notice.
- `languages/qrauth-passwordless-social-login-el_GR.po` updated via `msgmerge` against the new POT: existing translations preserved (there were none — the 0.1.0 scaffold shipped empty), new strings carried over with empty msgstrs ready for translation. `el_GR.mo` recompiled.

### Notes

No code changes, no behaviour changes — string extraction only. Translations are not filled in; the plugin continues to ship untranslated as it did in 0.1.0. Once the plugin is accepted on WordPress.org, translators contribute via <https://translate.wordpress.org/> against the POT shipped here.

## [0.1.9] — 2026-04-22

### Changed

- **Context-aware post-auth redirect.** `RestController::handle()` previously hard-coded the `redirect` field of its `/verify` response to `admin_url()`. That was fine for wp-login.php sign-ins but jarring on WooCommerce surfaces — a customer signing in at `/my-account/` or on the checkout page would be bounced to `/wp-admin/` (or, for non-admin users, whatever WP redirects subscribers away to). New `RestController::decide_redirect()` reads the request's `Referer` header and routes:
  - wp-login.php (and its `?action=register` variant) → `admin_url()`.
  - WooCommerce My Account (including sub-pages like `/edit-account/`, if configured) → `wc_get_page_permalink('myaccount')`.
  - WooCommerce checkout → `wc_get_page_permalink('checkout')` so the returning customer resumes their purchase.
  - Any other same-origin Referer (shortcode / Gutenberg / theme-managed page) → back to where they came from.
  - No Referer, cross-origin Referer, unparseable Referer → `admin_url()` fallback.
- All destinations are validated through WordPress core's `wp_validate_redirect()` before being returned, so a forged Referer header cannot turn this into an open redirect — off-origin URLs fall back to `admin_url()` regardless of what the browser sent. Referer is used purely to pick *which* same-origin page the user goes to; it's never trusted for authorisation.
- 13 new unit tests in `tests/Unit/Rest/RedirectDecisionTest.php` cover every branch (wp-login, both WC surfaces, sub-path matching, shared-prefix anti-collision, query-string preservation, safe fallbacks for each failure mode).

### Notes

The test harness required a couple of scaffolding pieces: `tests/stubs/wp-rest-request.php` is a minimal `WP_REST_Request` stand-in so unit tests can build a request with a Referer header without loading WP. The "WooCommerce not loaded" branch (where `function_exists('wc_get_page_permalink')` returns false) is exercised by the integration suite — the WP test harness runs without WC active, so every existing integration test runs through that code path already.

## [0.1.8] — 2026-04-21

### Fixed

- **Cross-device QR scan no longer authenticates the scanning device.** Background: the URL-param callback added in 0.1.5 is essential for mobile **same-device** sign-in (phone initiates, phone approves, phone comes back via redirect). But with no gating, the same mechanism inadvertently authenticated the scanning phone in the **cross-device** case: desktop renders QR → phone scans via camera → phone Safari loads qrauth.io/a/:token → user approves → phone Safari redirects back to `wp-login.php?qrauth_session_id=…&qrauth_signature=…` → the phone's adapter picked up the params and signed the phone in too. UX was unsettling; the user only wanted to sign in on the desktop.
- The fix: `AuthSessionProxyController::handle_create` now stamps a short-lived browser-scoped cookie (`qrauth_psl_initiator`, 5-minute TTL, `Path=/`, `Secure`, `SameSite=Lax`, NOT HttpOnly — the adapter reads it from JS) carrying the sessionId returned by the upstream response. `qrauth-adapter.js` gates its URL-param auto-complete on this cookie: present + matching → complete sign-in (same-device flow); absent or mismatched → scrub the URL params but stop there (cross-device flow).
- Multi-device result matrix:

  | Flow | Device that gets signed in | Why |
  |---|---|---|
  | Desktop QR + phone-app scan (no browser redirect) | Desktop | Desktop polling → `qrauth:authenticated` event |
  | Desktop QR + phone-browser scan + approve | Desktop | Desktop polling completes first; phone's adapter sees mismatched cookie and scrubs |
  | Mobile same-device (phone taps "Continue", phone approves) | Phone | Phone's cookie matches the sessionId it just initiated |

- **Multilingual sites (WPML, Polylang, Weglot) no longer require one redirect-URL registration per language.** Previously the widget emitted `redirect-uri="{home_url('/wp-login.php')}"`, and multilingual plugins rewrite `home_url()` with a language prefix — `/en/wp-login.php`, `/fr/wp-login.php`, etc. — which fails the QRAuth app's redirectUrls allowlist (exact-match after normalising trailing slash + query + fragment; path prefix is not normalised). Switched to `site_url('/wp-login.php')`, which multilingual plugins leave as the canonical admin-infrastructure URL. Admins register one URL for all languages.
- `SettingsView` callout and `readme.txt` installation step 4 updated to explain the multilingual behaviour explicitly so admins don't try to register per-language variants.

### Notes

After cross-device scan-with-phone, the scanning phone sees `wp-login.php` without auth material in the URL bar (the adapter already scrubbed) and on an unauthenticated session — the user closes the tab and returns to whatever they were doing. The desktop, which initiated the session and owns the cookie, signs in normally via its polling loop.

## [0.1.7] — 2026-04-21

### Added

- **WooCommerce integration.** New `Frontend\WooCommerceLogin` class binds to `woocommerce_login_form_end` and `woocommerce_register_form_end` — the standard template hooks WC themes expect for third-party sign-in surfaces. The widget appears inside WC's own forms, not adjacent to them, so themes that customise the form wrapper retain full layout control. Gated on `class_exists('WooCommerce')` at render time so the plugin stays bootable on non-WC sites; also gated on the per-surface `enabled_on` entries (`woocommerce-account`, `woocommerce-register`).
- **Settings UI** shows the two new WooCommerce checkboxes only when WC is actually active — avoids clutter for non-ecommerce sites. Both opt-in (off by default).

### Notes

WooCommerce's login template also renders on the checkout page when guest checkout is disabled, so hooking `woocommerce_login_form_end` once covers both My Account and checkout sign-in with a single binding. Reuses `Frontend\AssetEnqueue::enqueue_for_widget()` (introduced in 0.1.6) to activate the script handles on WC pages — zero bandwidth cost on non-WC pages.

## [0.1.6] — 2026-04-21

### Added

- **`[qrauth_login]` shortcode.** New `Frontend\Shortcode` registers the tag. Attributes: `display="inline|button"` (default `inline`, clamped to the allowlist), `mode="login|register"` (default `login`). Both attributes are validated against the widget's documented values; unknown values fall back to the defaults rather than propagating garbage to the element attributes. Opt in per-site via Settings → QRAuth → "Show widget on" → "Anywhere via shortcode" (off by default — activating the plugin never injects a widget on a surface the admin didn't explicitly mark up).
- **Front-end asset enqueueing.** `AssetEnqueue` now hooks `wp_enqueue_scripts` alongside the existing `login_enqueue_scripts`. On front-end pages the handles are **registered but not enqueued**; the shortcode callback (and future Gutenberg block / WooCommerce hook callbacks) flip activation via `AssetEnqueue::enqueue_for_widget()`. Pages that don't render the widget don't ship the ~65 KB IIFE — activated only on-demand, deduped across multiple widget instances per request.

### Changed

- `LoginWidget::emit()` refactored to delegate the `<qrauth-login>` markup to a new `LoginWidget::render_widget()` static helper. The shortcode reuses this helper so both surfaces render byte-identical element markup (attributes, base-url, redirect-uri, scope list). The divider (`— or —`) stays in `emit()` — it's login-form-specific and would read awkwardly on a custom page.

## [0.1.5] — 2026-04-21

### Added

- **Mobile same-device sign-in via URL-param callback.** `<qrauth-login>` now emits `redirect-uri` pointing at `home_url('/wp-login.php')`. After a user approves on their phone, qrauth.io's hosted approval page 302s back with `?qrauth_session_id=<cuid>&qrauth_signature=<envelope>` appended. The adapter (`assets/js/qrauth-adapter.js`) picks those params up on page load, scrubs them from the URL via `history.replaceState` (so a refresh can't replay a consumed signature), and POSTs to `/verify` the same way the `qrauth:authenticated` event path does. This is critical for mobile because the original tab is usually suspended while the user is on the qrauth.io tab — the polling loop never fires `qrauth:authenticated`, so the event path alone leaves the user stranded. Matches the pattern documented in `vqr/packages/docs/guide/web-components.md` §"URL-param callback" and already deployed in the comtel-sirens integration.
- **Settings → QRAuth:** new info callout surfaces the exact URL admins need to register in their QRAuth app's redirect-URL allowlist (typically `https://<site>/wp-login.php`). Required one-time dashboard setup for mobile sign-in.
- **readme.txt installation step 4:** instructions to register the login URL before trying phone sign-in.

### Notes

`redirect-uri` was briefly dropped in 0.1.1 (the CORS-proxy release) because qrauth.io exact-matches it against the app's allowlist and a URL not registered in the dashboard breaks every POST. 0.1.5 re-adds it with the administrator guidance to register the URL in their QRAuth app — the approach comtel-sirens has been using in production. The server-side allowlist comparison normalises trailing slashes and ignores query string + fragment, so registering `https://<site>/wp-login.php` is sufficient regardless of which query params WordPress may later append to that URL.

## [0.1.4] — 2026-04-21

### Fixed

- **Widget scope attribute name corrected.** The plugin emitted `<qrauth-login scope="identity email">`, but `@qrauth/web-components` reads the attribute `scopes` (plural — `get scopes(): string { return this.getAttribute('scopes') || 'identity'; }` in `packages/web-components/src/login.ts`). With the wrong attribute name, the component fell back to its default `'identity'`-only scope, POSTed `scopes: ['identity']` to the auth-session API, and the `/verify-result` response never carried an `email` field. That made `VerifyResult::$user_email` null, which in turn made **every** login fail with `provision_disabled` — whether auto-provisioning was on (the provision path refuses to create a user without an email, `UserLinker.php` line 80) or off (the email-match fallback on line 66 is gated on `null !== $result->user_email` and never ran). One missing `s` in an HTML attribute name.

### Notes

Latent since 0.1.0 — masked in sequence by the CORS boundary (fixed in 0.1.1), the UUID/base64url validator regexes (0.1.2), and the signature-envelope alphabet (0.1.3). Each fix uncovered the next latent bug in what turned out to be a four-deep stack. With 0.1.4 the end-to-end flow actually completes.

## [0.1.3] — 2026-04-21

### Fixed

- **`/verify` signature validator accepts the envelope format.** The monolith's approve path stores `${signingKey.keyId}:${base64sig}` on the session so `/verify-result` can look up the right key at verification time (see `packages/api/src/services/auth-session.ts` line 373). The 0.1.2 regex omitted the `:` separator, so every real envelope-format signature failed with `rest_invalid_param`. Alphabet broadened from `[A-Za-z0-9+/=_-]` to `[A-Za-z0-9+/=_:.-]`. The `.` was added at the same time for forward-compatibility with JWT-style compact envelopes.

### Notes

The 0.1.2 fix correctly diagnosed the two latent validator bugs (cuid vs UUID for sessionId, standard vs URL-safe base64 for signature) but missed that the signature is wrapped in an envelope before it reaches the wire — my test cases covered raw base64 but not the `keyId:sig` shape. Added a positive envelope-format test case so this can't regress the same way again.

## [0.1.2] — 2026-04-21

### Fixed

- **`/verify` input validators accept current QRAuth formats.** The `sessionId` validator was pinned to a strict UUID regex, but `AuthSession.id` has been cuid-shaped (`@default(cuid())`, ~24 lowercase alphanumeric chars, no dashes) all along. The `signature` validator accepted only base64url alphabet, but QRAuth's signing service returns DER-encoded ECDSA as standard base64 (with `+`, `/`, `=` padding). Both failures returned `rest_invalid_param` on every login attempt. Replaced with opaque-ID and union-alphabet allowlists: `sessionId` matches `^[A-Za-z0-9_-]{8,128}$`; `signature` matches `^[A-Za-z0-9+/=_-]{24,}$` with a 24-char minimum. Semantic validation remains upstream at `/verify-result`; these regexes are now explicitly "not-obviously-garbage" gates rather than format-pin checks.

### Notes

Both bugs predate the CORS-proxy work in 0.1.1 — they were latent in 0.1.0, masked because browsers couldn't reach `/verify` across the CORS boundary on third-party WP hosts. Once 0.1.1 enabled end-to-end auth via the same-origin proxy, every login hit the validators against real input for the first time and failed.

## [0.1.1] — 2026-04-21

### Added

- **Same-origin REST proxy.** New `AuthSessionProxyController` registers three routes under `qrauth-psl/v1`: `POST /api/v1/auth-sessions`, `GET /api/v1/auth-sessions/<id>`, and `GET /a/<token>`. The first two forward server-to-server to `{tenant_url}/api/v1/auth-sessions[/<id>]` with `wp_remote_request` (TLS, 10 s timeout, HTTP Basic auth injected from stored credentials) and return upstream status plus JSON body verbatim. The third 302s to the tenant origin so the mobile same-device approval page still lands on `qrauth.io` (WebAuthn RP-ID constraint).
- **Client Secret setting.** Password-input field under Settings → QRAuth with blank-preserve behaviour (submitting empty keeps the stored value; only a non-empty submission overwrites). Stored plaintext in `wp_options` — same trust boundary as any other WP-stored API credential. An admin notice flags the missing secret after upgrade.

### Changed

- **Widget `base-url` now points at the WordPress REST API** (`rest_url('qrauth-psl/v1')`), not `qrauth.io`. Eliminates browser CORS preflights on third-party WP installs.
- **Options schema:** `base_url` → `tenant_url`, new `client_secret`, computed `api_base_url`. Activation hook runs an idempotent migration — pre-0.1.1 installs are upgraded automatically on reactivation (no manual intervention needed).
- **Settings UI copy:** Tenant URL description rewritten for non-technical admins. The debug-only effective-REST-URL line is gated on `WP_DEBUG`.

### Removed

- Widget `redirect-uri` attribute. The value was exact-matched by qrauth.io against the app's registered redirect-URL allowlist (no prefix, no wildcard), and the plugin ignored any returned redirect anyway — `wp_set_auth_cookie` always lands the user on `admin_url()`.

## [0.1.0] — 2026-04-21

Initial public release. WordPress.org plugin directory submission.

### Added

- **Login widget.** `<qrauth-login>` rendered on `wp-login.php` (button mode) and `wp-login.php?action=register` (inline mode), gated on the configured Client ID and the `enabled_on` setting. Vendored IIFE from `@qrauth/web-components@0.4.0`, SHA-512-verified at build time.
- **Settings page.** Single row under `Settings → QRAuth` — Client ID, base URL, auto-provision toggle, default role, allowed scopes, injection points. Strict sanitizer: role clamped to `subscriber|contributor|author`, base URL must be `https://` (or `http://localhost|127.0.0.1` for dev), scopes intersected with `[identity, email, organization]` with `identity` mandatory.
- **Verification REST route.** `POST /wp-json/qrauth-psl/v1/verify`. Nonce via `X-WP-Nonce`; per-IP rate limit (10 / 5 min, IP hashed with `wp_salt()`); server-to-server call to `https://qrauth.io/api/v1/auth-sessions/verify-result` via `wp_remote_post` with TLS + 10 s timeout. Redirect is always `admin_url()` — QRAuth's `redirect` field is ignored (open-redirect risk zero).
- **User linking.** Three-step resolver: match by `qrauth_psl_user_id` meta, else by email, else provision (if enabled) with a 32-character random password and the configured default role. Role clamped defensively at linker level.
- **Profile unlink flow.** QRAuth section on `profile.php` / `user-edit.php` — linked state shows the remote user ID + link timestamp + nonce-gated "Unlink QRAuth" button. Per-user nonce (`qrauth_psl_unlink_<user_id>`) prevents cross-user CSRF. Editors cannot unlink other users (capability gate: `edit_user`).
- **Uninstaller.** Removes the single plugin option + sweeps `qrauth_psl_*` transients. Never touches `wp_users` or `wp_usermeta` — accounts and their link metadata survive uninstall, enabling frictionless reinstall.
- **i18n.** POT + Greek (`el_GR`) PO/MO files. All user-facing strings routed through `__()` / `_e()` / `_x()` with text domain `qrauth-passwordless-social-login`.

### Security

- Role ceiling enforced both in the settings sanitizer and at `UserLinker` dispatch time.
- Rate-limit key hashed with `wp_salt()` so a database dump cannot recover caller IPs.
- REST error responses report only machine codes (`nonce_failed`, `rate_limited`, `verify_failed`, `signature_invalid`, `session_not_approved`, `provision_disabled`, `internal_error`). Upstream error text is never leaked.
- Supply-chain integrity for the vendored IIFE: `bin/fetch-web-components.mjs` verifies the npm tarball against a pinned SHA-512 before extraction.
- No outbound analytics or telemetry.

### Compatibility

- PHP 8.2+ floor. Tested on PHP 8.2 and 8.3.
- WordPress 6.4+ floor. Tested against WP 6.4, 6.5, 6.6, 6.7, and the current release.
- No jQuery dependency. Adapter is an ES2020 IIFE under 2.5 KB.
