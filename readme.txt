=== QRAuth – Passwordless & Social Login ===
Contributors: aristech
Tags: login, passwordless, qr code, social login, authentication
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 0.1.14
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
4. In the QRAuth dashboard (**Apps → your app → Redirect URLs**), register your site's login URL — typically `https://<your-site>/wp-login.php`. Without this registration, sign-in on phones (same-device approval) cannot complete; desktop sign-in works without it. The exact URL to register is shown on the Settings → QRAuth page. One registration is enough even on multilingual sites (WPML, Polylang, Weglot): the plugin uses the language-neutral admin URL, not the translated home URL, so `/en/wp-login.php` / `/fr/wp-login.php` / etc. don't need separate entries.
5. Log out and try signing in via the "Sign in with QRAuth" button on wp-login.php.

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

1. wp-login.php with the "Sign in with QRAuth" button under the password field.
2. Widget active — animated QR, session countdown, and "Continue on this device" CTA for mobile users.
3. Settings → QRAuth — Client ID, Client Secret, Tenant URL, auto-provision toggle, default role, allowed scopes, and per-surface enable switches.
4. WooCommerce registration form — inline widget alongside WC's account-creation fields.

== Changelog ==

= 0.1.14 =
* Readme: `Contributors:` field set to `aristech` — the verified wordpress.org account that owns the submission. The prior value (`qrauth`) wasn't a registered wordpress.org profile and would have rendered as a broken contributor badge on the plugin directory page.

= 0.1.13 =
* Security (low-severity hardening): the Tenant URL sanitiser now rejects `http://localhost` and `http://127.0.0.1` values on production sites — they're only accepted when `WP_DEBUG` is on (local development) or when a site operator explicitly opts in via the new `qrauth_psl_allow_localhost_tenant_url` filter. Closes a pentest finding: an admin with `manage_options` could previously point Tenant URL at arbitrary localhost ports (e.g. MySQL, Redis), using the plugin's outbound `wp_remote_request` as a port-scanning oracle against the WP host's internal network. Now admin-gated SSRF via this path is blocked by default; `https://` tenants remain accepted unchanged.

= 0.1.12 =
* CI/release infrastructure: bumped all GitHub Actions flagged by the Node-20 deprecation warning (`actions/checkout`, `actions/setup-node`, `softprops/action-gh-release`, `actions/upload-artifact`, `ramsey/composer-install`) to current major versions so every runner step uses Node.js 24. No plugin behaviour changes — only the CI environment that builds + releases the plugin changed.

= 0.1.11 =
* Fixed: three plugin-check warnings surfaced while preparing the WordPress.org submission. (1) The release ZIP now includes `composer.json` alongside `vendor/` so reviewers can see the provenance of the vendored dependencies. (2) Removed the explicit `load_plugin_textdomain()` call from plugin bootstrap — WordPress 4.6+ auto-loads translations for plugins hosted on wordpress.org, and an explicit call is now discouraged. (3) Trimmed the 0.1.8 upgrade notice to stay under WordPress.org's 300-character limit.

= 0.1.10 =
* i18n: regenerated the translation template (`languages/qrauth-passwordless-social-login.pot`) and the Greek scaffold (`el_GR.po`) so every user-facing string added between 0.1.1 and 0.1.9 (Client Secret field, Tenant URL description, per-surface enable switches for shortcode and WooCommerce, mobile sign-in setup callout, etc.) is now available to translators. No behaviour changes; string extraction only.

= 0.1.9 =
* Changed: after a successful sign-in, customers on WooCommerce surfaces now land on their My Account page (or the checkout step, if that's where they started) instead of wp-admin. Sign-ins from wp-login.php still go to wp-admin. Sign-ins from a custom page (shortcode) go back to that page. Decided server-side from the request's Referer, validated same-origin via WordPress core's `wp_validate_redirect` so a forged Referer can't turn this into an open redirect.

= 0.1.8 =
* Fixed: cross-device QR scan no longer inadvertently signs in the scanning device. Previously, a phone that scanned a desktop-initiated QR and approved on qrauth.io would get redirected back to wp-login.php and auto-sign-in, because the landing-page adapter couldn't distinguish "this browser initiated the session" from "this browser just happens to be visiting the redirect URL". The proxy now stamps a short-lived, browser-scoped cookie at session-create time, and the adapter only auto-completes sign-in when that cookie matches the sessionId in the URL — same-device mobile flow unaffected, cross-device leaves the scanning device on the login page.
* Fixed: multilingual sites using WPML / Polylang / Weglot no longer need a separate redirect-URL registered per language. The widget now emits its `redirect-uri` using WordPress's language-neutral admin URL (`site_url`) instead of the translated home URL, so `/en/wp-login.php`, `/fr/wp-login.php`, etc. all resolve to the same canonical registration in the QRAuth dashboard.

= 0.1.7 =
* Added: WooCommerce integration. When WooCommerce is active, Settings → QRAuth shows two new checkboxes — "WooCommerce My Account + checkout login" (covers /my-account/ and the checkout page's returning-customer sign-in) and "WooCommerce registration form". The widget appears inside WooCommerce's own forms via the standard `woocommerce_login_form_end` / `woocommerce_register_form_end` template hooks.
* Settings checkboxes are hidden on non-WooCommerce sites — the plugin stays clean on simple blogs.

= 0.1.6 =
* Added: `[qrauth_login]` shortcode for placing the widget on any page, post, or widget area. Supports `display="inline|button"` (default `inline`) and `mode="login|register"` (default `login`). Opt in via Settings → QRAuth → "Show widget on" → "Anywhere via shortcode".
* Widget asset loading now runs on front-end pages when the shortcode is used, not just on `wp-login.php`. The ~65 KB bundle is only emitted on pages that actually render the widget — unused shortcode-enabled sites pay zero bandwidth cost on pages without the tag.

= 0.1.5 =
* Added: mobile same-device sign-in. The login widget now emits `redirect-uri` pointing at `wp-login.php`, and the adapter picks up the `qrauth_session_id` / `qrauth_signature` query params that qrauth.io appends when the user approves on their phone. The WP tab being suspended while the user was on qrauth.io no longer breaks the flow.
* Added: Settings → QRAuth now shows the exact URL admins need to register in their QRAuth app's redirect-URL allowlist (typically `https://<site>/wp-login.php`). Required one-time setup for phone sign-in; desktop still works without it.

= 0.1.4 =
* Fixed: logins completed past `/verify` on 0.1.3 but bounced with `provision_disabled` — regardless of whether auto-provisioning was on. The widget's scope attribute was emitted as `scope` (singular) while the QRAuth web component reads `scopes` (plural), so only the default `identity` scope was requested and `/verify-result` returned no email. Email-based account matching and new-user provisioning both rely on having the email, so both paths failed with the same error code. Restored the correct attribute name — no action required on upgrade.

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

= 0.1.14 =
Cosmetic readme fix — `Contributors:` now points at the actual wordpress.org profile. No runtime changes.

= 0.1.13 =
Security hardening — Tenant URL sanitiser now rejects plain-http localhost values unless `WP_DEBUG` is on or the `qrauth_psl_allow_localhost_tenant_url` filter opts in. Blocks an admin-gated SSRF probe path. No action required for sites using `https://` tenants.

= 0.1.12 =
CI / release-infrastructure bump only — runners now use Node.js 24. Zero runtime behaviour changes.

= 0.1.11 =
Plugin-check hygiene before WordPress.org submission: composer.json now shipped alongside vendor/, discouraged `load_plugin_textdomain()` call removed, 0.1.8 upgrade notice trimmed under the 300-char cap. No runtime behaviour changes.

= 0.1.10 =
Translation scaffolding only — no behaviour changes. New UI strings from 0.1.1 → 0.1.9 are now picked up by the POT + el_GR.po so translators have a current baseline to work from.

= 0.1.9 =
Customers signing in through a WooCommerce form now land on My Account (or the checkout step they started on) instead of wp-admin. No configuration change required.

= 0.1.8 =
Mobile / multilingual fixes: a phone scanning a QR from another device no longer signs itself in; the redirect URL is language-neutral so WPML / Polylang / Weglot sites need only one allowlist entry.

= 0.1.7 =
Adds WooCommerce support — the widget can now appear on the My Account login/register forms and on the checkout sign-in step. Opt in per-surface under Settings → QRAuth.

= 0.1.6 =
Adds a `[qrauth_login]` shortcode so the widget can live on any page, not just wp-login.php. Opt in from Settings → QRAuth. No existing configuration changes on upgrade.

= 0.1.5 =
Adds mobile same-device sign-in. One-time setup: register your `/wp-login.php` URL in your QRAuth app's redirect-URL allowlist (Settings → QRAuth shows the exact URL). Desktop sign-in is unaffected either way.

= 0.1.4 =
Required hotfix. 0.1.3 accepted QRAuth signatures but rejected every login with `provision_disabled` because the widget was requesting only the `identity` scope (the scope attribute name was wrong). Upgrade to restore end-to-end sign-in.

= 0.1.3 =
Required hotfix. 0.1.2 still failed to accept real QRAuth signatures because the envelope separator `:` wasn't in the allowed alphabet. Upgrade to restore end-to-end sign-in.

= 0.1.2 =
Recommended hotfix. Unblocks end-to-end QRAuth sign-in on sites running 0.1.1 — the `/verify` route's input validators were too strict and rejected every real login. No configuration change required.

= 0.1.1 =
Adds a same-origin REST proxy that eliminates browser CORS errors. Upgrading requires adding a Client Secret in Settings → QRAuth; the existing Client ID and the renamed Tenant URL (previously "Base URL") are migrated automatically.

= 0.1.0 =
Initial public release.
