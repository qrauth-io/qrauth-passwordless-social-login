=== QRAuth – Passwordless & Social Login ===
Contributors: qrauth
Tags: login, passwordless, qr code, social login, authentication
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 0.1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Passwordless sign-in for WordPress. Users scan a QR code with the QRAuth mobile app — no passwords, no forms, no OAuth apps to register.

== Description ==

QRAuth replaces the password field on your WordPress login page with a drop-in QR widget. Users sign in by scanning with the QRAuth mobile app; a cryptographic signature is verified server-to-server before WordPress sets the auth cookie. Social login (Google, GitHub, Microsoft, Apple) is brokered by QRAuth's hosted approval page, so you never have to register an OAuth app or hold a client secret.

**One Client ID is the only configuration.** Paste it into Settings → QRAuth and the widget appears on wp-login.php. Everything else — the approval flow, the signing, the token refresh — lives in the QRAuth platform.

**Account safety is the default.** Auto-provisioning is off out of the box: only WordPress users who already exist (matched on email) can sign in via QRAuth. Flip on auto-provisioning and new users get the default role you pick (never above author — editor and administrator are deliberately unavailable). The plugin never stores the signing material, never issues a redirect outside your site, and never touches your user table on uninstall.

**Self-hosted, no third-party scripts on wp-login.php.** The QRAuth web component ships vendored inside the plugin — the only outbound call is from your server to QRAuth's verification endpoint during a sign-in attempt.

== Installation ==

1. Upload the plugin or install via WP-Admin → Plugins → Add New.
2. Activate through the 'Plugins' menu in WordPress.
3. Go to Settings → QRAuth and paste your Client ID and Client Secret from https://qrauth.io/dashboard/apps/create. The Client Secret is used server-side only — it never reaches the browser.
4. Log out and try signing in via the "Sign in with QRAuth" button on wp-login.php.

== Frequently Asked Questions ==

= Do my users need a QRAuth account? =

Only if they sign in via QR. They install the free QRAuth mobile app once, scan the code on your login page, and approve on their phone. For social login (Google, GitHub, Microsoft, Apple) the user simply taps the provider on QRAuth's hosted approval page — they don't need a QRAuth account at all.

= Where are passwords stored? =

They aren't. Auto-provisioned accounts get a 32-character random password that nobody (including you) holds or needs. WordPress users who already had a password keep it — QRAuth just becomes an additional sign-in method alongside the usual form.

= Does it work with existing WordPress users? =

Yes. If a scanned account's email matches an existing WordPress user, they log into that account — no duplicate is created. The match is remembered via user meta, so subsequent sign-ins skip the email lookup.

= What role do new users get? =

Whatever you pick in Settings → QRAuth, from subscriber / contributor / author. Editor and administrator are not available as auto-provision roles by design.

= Can I unlink a WordPress account from QRAuth? =

Yes — from Users → Your Profile there's a QRAuth section with an "Unlink QRAuth" button. Admins can unlink other users from their profiles too. The WordPress account (role, posts, comments, history) is preserved; only the link metadata is removed.

= Can I remove the plugin later? =

Yes. Deactivate and all your users stay. Uninstall removes the plugin's settings row and its rate-limit transients — it never deletes WordPress user accounts or their content. If you reinstall later, existing users re-link automatically on their next QRAuth sign-in.

= Does the plugin phone home with analytics? =

No. There's no telemetry, no analytics, no third-party scripts. The only outbound request is from your server to `https://qrauth.io/api/v1/auth-sessions/verify-result` during a login attempt, carrying only the session ID and the signature the user's phone produced.

= How is rate limiting handled? =

The REST verify route caps requests at 10 per 5 minutes per hashed IP. The hash uses `wp_salt()`, so a database dump can't recover caller IPs. Each verify attempt costs one outbound call to QRAuth's servers, so tight limits protect both your site and ours.

= Does it work on multisite? =

Per-site activation works today. Network-activated multisite is tracked for a future release.

== Screenshots ==

1. Login page with "Sign in with QRAuth" button under the password field.
2. QR widget — scan with the QRAuth phone app or approve on the linked device.
3. Settings → QRAuth — Client ID, base URL, scopes, default role.
4. User profile — linked state with the "Unlink QRAuth" button.
5. Registration page with the inline QR widget.

== Changelog ==

= 0.1.3 =
* Fixed: `/verify` still rejected real logins on 0.1.2 with `rest_invalid_param` because the signature value QRAuth returns is an envelope (`<keyId>:<base64sig>`), and the validator's alphabet didn't include the `:` separator. Added `:` and `.` to the accepted character set. No action required on upgrade.

= 0.1.2 =
* Fixed: `/verify` rejected every real login with `rest_invalid_param` because the input validators were pinned to old formats. `sessionId` now accepts QRAuth's current cuid format (not only UUIDs); `signature` now accepts standard base64 (with `+`, `/`, `=`) in addition to base64url. No action required on upgrade — auth on existing sites starts working again automatically.

= 0.1.1 =
* Added a same-origin REST proxy (`qrauth-psl/v1/api/v1/auth-sessions`, `…/a/<token>`) so the login widget talks to your site's own WordPress REST API. No more browser-level CORS errors on third-party hosts.
* New **Client Secret** setting. Used server-side only to authenticate the proxy against qrauth.io. Never exposed to the browser.
* Options rename: `base_url` → `tenant_url`. Existing configurations are migrated automatically on upgrade; no manual action required.
* Tenant URL help text clarified for non-technical admins; a debug line showing the effective REST URL is now emitted only when `WP_DEBUG` is on.
* Dropped the widget's `redirect-uri` attribute — it was never used by the plugin and triggered exact-match allowlist rejections on qrauth.io.

= 0.1.0 =
* Initial public release.
* Passwordless sign-in on wp-login.php via the vendored QRAuth web component (v0.4.0).
* Server-side cryptographic verification against QRAuth's `/verify-result` endpoint with TLS + 10 s timeout.
* Auto-provisioning (off by default) with role clamped to subscriber / contributor / author.
* Per-user profile UI for unlinking QRAuth, preserving the WordPress account.
* Uninstall cleanup that removes plugin options + transients but never touches `wp_users` or `wp_usermeta`.
* REST route rate-limited at 10 requests per 5 minutes per hashed IP.
* Full i18n scaffolding (POT + Greek translation source).

== Upgrade Notice ==

= 0.1.3 =
Required hotfix. 0.1.2 still failed to accept real QRAuth signatures because the envelope separator `:` wasn't in the allowed alphabet. Upgrade to restore end-to-end sign-in.

= 0.1.2 =
Recommended hotfix. Unblocks end-to-end QRAuth sign-in on sites running 0.1.1 — the `/verify` route's input validators were too strict and rejected every real login. No configuration change required.

= 0.1.1 =
Adds a same-origin REST proxy that eliminates browser CORS errors. Upgrading requires adding a Client Secret in Settings → QRAuth; the existing Client ID and the renamed Tenant URL (previously "Base URL") are migrated automatically.

= 0.1.0 =
Initial public release.
