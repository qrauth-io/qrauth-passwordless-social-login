=== QRAuth – Passwordless & Social Login ===
Contributors: aristech
Tags: login, passwordless, qr code, social login, authentication
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 0.1.22
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Passwordless sign-in for WordPress. Users scan a QR code with the QRAuth mobile app — no passwords, no forms, no OAuth apps to register.

== Description ==

QRAuth replaces the password field on your WordPress login page with a drop-in QR widget. Users sign in by scanning with the QRAuth mobile app; a cryptographic signature is verified server-to-server before WordPress sets the auth cookie. Social login (Google, GitHub, Microsoft, Apple) is brokered by QRAuth's hosted approval page, so you never have to register an OAuth app or hold a client secret.

**One Client ID is the only configuration.** Paste it into Settings → QRAuth and the widget appears on wp-login.php. Everything else — the approval flow, the signing, the token refresh — lives in the QRAuth platform.

**Account safety is the default.** Auto-provisioning is off out of the box: only WordPress users who already exist (matched on email) can sign in via QRAuth. Flip on auto-provisioning and new users are created as Subscriber — that's the only role available, intentionally and at every layer (settings UI, sanitiser, runtime). Operators who need a different role for an individual user can change it manually via Users → All Users after their first sign-in. The plugin never stores the signing material, never issues a redirect outside your site, and never touches your user table on uninstall.

**Self-hosted, no third-party scripts on wp-login.php.** The QRAuth web component ships vendored inside the plugin — the only outbound call is from your server to QRAuth's verification endpoint during a sign-in attempt.

**Open source build.** The compressed JavaScript at `assets/js/qrauth-components.js` is built from publicly available TypeScript source at https://github.com/qrauth-io/qrauth/tree/main/packages/web-components. The unminified source files are also vendored alongside the minified bundle inside this plugin (`assets/js/source/`) for offline review. See the **Source** section below for build instructions.

== External services ==

This plugin connects to QRAuth (https://qrauth.io) — the identity verification service that performs the actual passwordless / social sign-in. QRAuth is operated by ProgressNet, the publisher of this plugin. Without QRAuth there is no widget and no sign-in.

**What the service is and what it is used for**

QRAuth verifies that the user who scanned the QR code (or completed a social-provider flow on the hosted approval page) is the same person who initiated the sign-in on your WordPress site, then returns a signed assertion that the plugin uses to set the WordPress auth cookie.

**What data is sent and when**

* **Auth-session creation** — when a visitor opens a page that hosts the widget (wp-login.php, the registration form, a shortcode-enabled page, or a WooCommerce sign-in form), the plugin's same-origin REST proxy sends your Client ID, Client Secret (server-side only — never exposed to the browser), and the host page URL to `https://qrauth.io/api/v1/auth-sessions`. No visitor data is included in this request.
* **Sign-in verification** — when the visitor approves the sign-in (by scanning with the QRAuth mobile app or completing a social-provider flow on QRAuth's hosted approval page), the plugin's REST proxy fetches the verified result from `https://qrauth.io/api/v1/auth-sessions/verify-result`. The response carries the QRAuth user identifier and, when the `email` scope is allowed in Settings → QRAuth, the user's email address. The plugin uses this only to locate or create the matching WordPress user; nothing beyond a hashed link reference is retained.
* **Hosted approval page** — when a visitor on a phone taps "Continue with QRAuth", the browser navigates to `https://qrauth.io/a/<token>` to complete the social-provider flow. This is a standard cross-domain navigation initiated by the visitor.

The vendored web component (`assets/js/qrauth-components.js`) is served from your own WordPress site — there is no third-party JavaScript on wp-login.php, and the component does not contact qrauth.io directly from the browser; all server-to-server calls are proxied via your site's REST API.

**Service terms and policies**

* Terms of Service: https://qrauth.io/terms
* Privacy Policy: https://qrauth.io/privacy
* Data Processing Addendum: https://qrauth.io/dpa
* List of Sub-processors: https://qrauth.io/subprocessors

== Source ==

<!-- Keep the banner code block below in sync with the first lines of assets/js/qrauth-components.js after every web-components version bump. -->

The compiled bundle at `assets/js/qrauth-components.js` carries the following banner header at the top of the file:

```
/*!
 * @qrauth/web-components v0.4.1
 * Vendored by qrauth-passwordless-social-login. Do not edit by hand.
 *
 * Source:  https://github.com/qrauth-io/qrauth/tree/main/packages/web-components
 * License: MIT
 * npm:     https://www.npmjs.com/package/@qrauth/web-components
 * Build:   `npm install && npm run build:assets` (see bin/fetch-web-components.mjs)
 *
 * The unminified TypeScript source for this bundle is also vendored at
 * assets/js/source/ — see assets/js/source/README.md for provenance.
 */
```

The unminified TypeScript source files are also vendored alongside the compiled bundle inside this plugin (`assets/js/source/`) for offline review.

The plugin's own source — PHP, the small browser adapter (`assets/js/qrauth-adapter.js`), build scripts, tests, and CI — is publicly maintained under GPL-2.0-or-later at:

* Plugin source repository: https://github.com/qrauth-io/qrauth-passwordless-social-login

The PHP and `assets/js/qrauth-adapter.js` shipped in the plugin ZIP are non-minified — read them directly without checking out the repo.

The vendored file `assets/js/qrauth-components.js` is a pinned production build of the public `@qrauth/web-components` library. The non-compiled source for that library is openly available:

* Source repository: https://github.com/qrauth-io/qrauth/tree/main/packages/web-components (MIT-licensed)
* npm release: https://www.npmjs.com/package/@qrauth/web-components — pinned to v0.4.1, sha512 in `package.json` under the `qrauth.webComponentsIntegrity` key
* Build instructions for the upstream library: https://github.com/qrauth-io/qrauth/blob/main/BUILDING.md

To regenerate the vendored bundle from the pinned npm release, clone the plugin source repository linked above and run from its root:

`npm install`
`npm run build:assets`

The build script `bin/fetch-web-components.mjs` (kept in the plugin source repository, not in the WordPress.org plugin ZIP) downloads the npm tarball, verifies its sha512 SRI hash against `package.json#qrauth.webComponentsIntegrity`, extracts the IIFE build, and writes it to `assets/js/qrauth-components.js`. CI runs the same script before the WordPress.org plugin-check job, so the bundle distributed on the directory always matches the published npm release. To rebuild from upstream source instead of the pinned tarball, follow `BUILDING.md` in the upstream library repository above.

== Installation ==

1. Upload the plugin or install via WP-Admin → Plugins → Add New.
2. Activate through the 'Plugins' menu in WordPress.
3. Go to Settings → QRAuth and paste your Client ID and Client Secret from https://qrauth.io/dashboard. The Client Secret is used server-side only — it never reaches the browser.
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

Subscriber, hardcoded. The settings UI only offers Subscriber, the sanitiser clamps any other value to Subscriber, and the provisioner ignores the stored option entirely and uses Subscriber unconditionally — this aligns with WordPress.org plugin directory guidelines for sign-in plugins that create users post-external-verification. There is no plugin-provided code path (filter, action, option, or constant) to elevate the auto-provisioning role. If a particular user needs a higher role, change it manually via Users → All Users after their first sign-in.

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
= 0.1.22 =
* Re-submission for WordPress.org plugin directory review. No source-code changes vs 0.1.21 — only the version metadata is bumped. The round-4 review comments cited line numbers that pre-dated 0.1.21's `wp_login_url()` migration, so this bump exists only to ensure the reviewer's tooling re-scans the post-fix tree.

= 0.1.21 =
* Use `wp_login_url()` instead of hardcoded `site_url('/wp-login.php')` for the two locations that reference the W login URL. This respects security plugins that move wp-login.php to a custom URL and is the recommended pattern per the WordPress developer handbook.

= 0.1.20 =
* Compliance: vendored the TypeScript source for the bundled `@qrauth/web-components` library at `assets/js/source/` alongside the minified bundle, so reviewers and integrators can read the unminified source without leaving the plugin. The readme's `== Source ==` section (renamed from `== External resources ==` for clarity) and the Description's "Open source build" paragraph also link to the canonical upstream repository and build instructions. WordPress.org plugin guideline 4 (human-readable code).

= 0.1.19 =
* Account safety: the `qrauth_psl_provisioning_role` filter introduced in 0.1.16 has been removed. Auto-provisioned users are now hardcoded to the `subscriber` role at every layer — settings UI, sanitiser, and runtime provisioner — with no programmatic path (filter, action, option, or constant) to elevate. Operators who need a different role for an individual user must update it manually via Users → All Users after first sign-in. Identified during WordPress.org plugin directory review (creating users post-external-verification must be capped at the lowest privilege role).

= 0.1.18 =
* Security ecosystem compatibility: the `/verify` route now fires the canonical `wp_login` action on every successful sign-in (mirroring what core's `wp_signon()` does after `wp_set_auth_cookie()`) and `wp_login_failed` on `signature_invalid` rejections. Security plugins (Limit Login Attempts Reloaded, Wordfence, Solid Security), audit-log plugins (WP Activity Log, Simple History), MFA gates, and "last login" / session-tracking plugins now see QRAuth sign-ins exactly as if the user had authenticated through `wp-login.php`. Identified during WordPress.org plugin directory review — closes the "creating your own login method can bypass security plugins" concern raised in the review. No configuration changes required.
* Documentation: added an `== External resources ==` section to the readme that publishes the plugin's own GitHub repository (https://github.com/qrauth-io/qrauth-passwordless-social-login, GPL-2.0-or-later) and points at the public source for the vendored `@qrauth/web-components` library (https://github.com/qrauth-io/qrauth/tree/main/packages/web-components, MIT) along with the npm release the bundle is pinned to and the `npm run build:assets` step that reproduces it. The vendored file's banner now names its source repository, license, npm release, and build entry-point inline. The plugin header's `Plugin URI:` now points at the GitHub repository instead of the QRAuth platform homepage, and a top-level `LICENSE` file (GPL-2.0) has been added to the repository so forkers and reviewers see the licence at a glance. Addresses WordPress.org plugin directory feedback that compiled JS must link to a publicly maintained, human-readable source.

= 0.1.17 =
* Fixed: same-device mobile sign-in's "Continue with QRAuth" URL no longer drops its query string when proxying to the QRAuth tenant. Without this, the signal that distinguishes same-device clicks from cross-device QR scans was being stripped at the WordPress proxy layer, so the hosted approval page could no longer decide reliably whether to redirect after approval — sites with a strict `Referrer-Policy` could end up landing the user on a "you can close this page" terminal state instead of returning them to wp-login.php. The `/a/<token>` proxy now forwards the query string verbatim, matching the existing behaviour documented for the `/api/v1/auth-sessions/<id>` proxy.

= 0.1.16 =
* Security: bumped vendored QRAuth web component to 0.4.1, which renders QR codes locally instead of fetching them from a third-party image service (api.qrserver.com). No more outbound calls leave the visitor's browser to hosts other than your own site. Identified during WordPress.org plugin review.
* Privacy: the readme now carries an explicit `== External services ==` section documenting every outbound call the plugin makes, with links to the QRAuth Terms, Privacy Policy, DPA, and Sub-processors list.
* Account safety: the auto-provisioning UI now offers only "Subscriber" — Contributor and Author have moved behind the new `qrauth_psl_provisioning_role` filter, accessible only via code. Editor and Administrator remain hard-blocked at every layer, as before. Existing sites configured with Contributor or Author will fall back to Subscriber on the next save; until then their stored value is unchanged.

= 0.1.15 =
* Author URI: https://github.com/aristech

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

= 0.1.22 =
Version bump only — no source-code changes vs 0.1.21. Re-submission for WordPress.org plugin directory review.

= 0.1.21 =
Better compatibility with security plugins that move the login URL: the plugin now uses wp_login_url() instead of hardcoded /wp-login.php references.

= 0.1.20 =
WordPress.org plugin review compliance: unminified TypeScript source for the bundled web-components library is now vendored alongside the compiled bundle. No runtime behaviour change.

= 0.1.19 =
Recommended. Removes the `qrauth_psl_provisioning_role` filter — auto-provisioned users are now hardcoded to Subscriber with no code path to elevate. Operators needing a different role for a user must change it manually via Users → All Users after first sign-in. WordPress.org review fix.

= 0.1.18 =
Recommended. The `/verify` route now fires `wp_login` on success and `wp_login_failed` on signature mismatch so security plugins (Limit Login Attempts, Wordfence), audit logs, MFA gates, and last-login trackers see QRAuth sign-ins as if they came through wp-login.php. Also adds an `== External resources ==` readme section, switches `Plugin URI:` to the plugin's GitHub repo, and adds a top-level GPL-2.0 LICENSE file. No configuration changes required.

= 0.1.17 =
Mobile sign-in robustness: the "Continue with QRAuth" flow now reliably returns users to your site after approval, including on sites that send a strict Referrer-Policy header. No configuration change required.

= 0.1.16 =
WordPress.org review fixes. Vendored web component bumped to 0.4.1 (no more api.qrserver.com fetch). External services section added to readme. Auto-provision role UI is now Subscriber-only — sites that need Contributor/Author must use the new `qrauth_psl_provisioning_role` filter.

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
