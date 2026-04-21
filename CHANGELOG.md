# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
