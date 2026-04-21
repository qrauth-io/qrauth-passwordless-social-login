# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
