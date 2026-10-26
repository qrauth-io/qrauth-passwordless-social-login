=== QRAuth – Passwordless & Social Login ===
Contributors: aristech
Tags: login, passwordless, qr code, social login, authentication
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Passwordless sign-in for WordPress. Visitors sign in with QRAuth: a quick scan on a computer, one tap on a phone. No passwords to remember.

== Description ==

Adds a **Sign in with QRAuth** button to your WordPress login page. Visitors press it, confirm it is them on QRAuth, and come straight back signed in. On a computer they scan a code with their phone's camera. On a phone they confirm on the phone itself, with no code to scan. QRAuth handles Google, GitHub, Microsoft and Apple sign-in for you, so you never register an OAuth app with any of them.

**Five-minute setup.** Create a free QRAuth account, add your website in the QRAuth dashboard, and paste the Client ID and Client Secret into Settings → QRAuth. The settings page walks you through each step and shows the exact address to copy.

**Built on OpenID Connect.** Sign in with QRAuth uses the authorization code flow with PKCE, a one-time state bound to the visitor's browser, and a nonce. WordPress checks the ID token's signature against QRAuth's published keys before anyone is signed in. The QRAuth sign-in service is OpenID Certified.

**Account safety is the default.**

* Only people who already have a WordPress account can sign in, matched on an email address QRAuth has verified. Turn on account creation if you want open registration.
* New accounts are always created as Subscriber, at every layer (settings, sanitiser, runtime). Change a role by hand under Users → All Users if needed.
* Administrators and Editors are never matched by email. They link their account once from their own profile page while signed in with their password.
* The plugin never redirects outside your site and never touches your user table on uninstall.

**Works where your users sign in:** wp-login.php, the registration form, WooCommerce My Account and checkout, and any page via the `[qrauth_login]` shortcode.

**Older sites: embedded widget.** Sites set up before Sign in with QRAuth existed can keep using the embedded QR widget. It is used only while no Sign in with QRAuth client is configured, and switching over keeps every linked account.

**Open source build.** The compressed JavaScript at `assets/js/qrauth-components.js` is built from publicly available TypeScript source at https://github.com/qrauth-io/qrauth/tree/main/packages/web-components. The unminified source files are also vendored alongside the minified bundle inside this plugin (`assets/js/source/`) for offline review. See the **Source** section below for build instructions.

== External services ==

This plugin connects to QRAuth (https://qrauth.io) — the identity verification service that performs the actual passwordless / social sign-in. QRAuth is operated by ProgressNet, the publisher of this plugin. Without QRAuth there is no widget and no sign-in.

**What the service is and what it is used for**

QRAuth verifies that the user who scanned the QR code (or completed a social-provider flow on the hosted approval page) is the same person who initiated the sign-in on your WordPress site, then returns a signed assertion that the plugin uses to set the WordPress auth cookie.

**What data is sent and when**

* **Auth-session creation** — when a visitor opens a page that hosts the widget (wp-login.php, the registration form, a shortcode-enabled page, or a WooCommerce sign-in form), the plugin's same-origin REST proxy sends your Client ID, Client Secret (server-side only — never exposed to the browser), and the host page URL to `https://qrauth.io/api/v1/auth-sessions`. No visitor data is included in this request.
* **Sign-in verification** — when the visitor approves the sign-in (by scanning the QR code with their phone or completing a social-provider flow on QRAuth's hosted approval page), the plugin's REST proxy fetches the verified result from `https://qrauth.io/api/v1/auth-sessions/verify-result`. The response carries the QRAuth user identifier and, when the `email` scope is allowed in Settings → QRAuth, the user's email address. The plugin uses this only to locate or create the matching WordPress user; nothing beyond a hashed link reference is retained.
* **Hosted approval page** — when a visitor on a phone taps "Continue with QRAuth", the browser navigates to `https://qrauth.io/a/<token>` to complete the social-provider flow. This is a standard cross-domain navigation initiated by the visitor.

* **Sign in with QRAuth (OpenID Connect), when an OIDC client is configured** — the visitor's browser is redirected to `https://id.qrauth.io/authorize` with your OIDC Client ID, the redirect URI, and one-time state, nonce and PKCE values; the visitor signs in on QRAuth's pages and is redirected back. Your server then calls `https://id.qrauth.io`: the discovery document and signing keys (`/.well-known/openid-configuration`, `/.well-known/jwks.json`, nothing sent), the token endpoint (`/token`: your OIDC Client ID and Client Secret, the one-time authorization code and PKCE verifier), and the userinfo endpoint (`/userinfo`: the access token). The responses carry a site-specific QRAuth user identifier and, with the visitor's consent, their name and email address and whether QRAuth has verified it. The plugin uses these only to sign the visitor in to the matching WordPress account. The OIDC Client Secret is stored in your site's options table, like the widget's Client Secret, and is never sent to the browser.

The vendored web component (`assets/js/qrauth-components.js`) is served from your own WordPress site — there is no third-party JavaScript on wp-login.php, and the component does not contact qrauth.io directly from the browser; all server-to-server calls are proxied via your site's REST API.

**Service terms and policies**

* Terms of Service: https://qrauth.io/terms
* Privacy Policy: https://qrauth.io/privacy
* Data Processing Addendum: https://qrauth.io/dpa
* List of Sub-processors: https://qrauth.io/subprocessors

== Source ==

<!-- Keep the banner code block below in sync with the first lines of assets/js/qrauth-components.js after every web-components version bump. -->

The compiled bundle at `assets/js/qrauth-components.js` carries the following banner header at the top of the file:

    /*!
     * @qrauth/web-components v0.4.2
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

The unminified TypeScript source files are also vendored alongside the compiled bundle inside this plugin (`assets/js/source/`) for offline review.

The plugin's own source — PHP, the small browser adapter (`assets/js/qrauth-adapter.js`), build scripts, tests, and CI — is publicly maintained under GPL-2.0-or-later at:

* Plugin source repository: https://github.com/qrauth-io/qrauth-passwordless-social-login

The PHP and `assets/js/qrauth-adapter.js` shipped in the plugin ZIP are non-minified — read them directly without checking out the repo.

The vendored file `assets/js/qrauth-components.js` is a pinned production build of the public `@qrauth/web-components` library. The non-compiled source for that library is openly available:

* Source repository: https://github.com/qrauth-io/qrauth/tree/main/packages/web-components (MIT-licensed)
* npm release: https://www.npmjs.com/package/@qrauth/web-components — pinned to v0.4.2, sha512 in `package.json` under the `qrauth.webComponentsIntegrity` key
* Build instructions for the upstream library: https://github.com/qrauth-io/qrauth/blob/main/BUILDING.md

To regenerate the vendored bundle from the pinned npm release, clone the plugin source repository linked above and run from its root:

`npm install`
`npm run build:assets`

The build script `bin/fetch-web-components.mjs` (kept in the plugin source repository, not in the WordPress.org plugin ZIP) downloads the npm tarball, verifies its sha512 SRI hash against `package.json#qrauth.webComponentsIntegrity`, extracts the IIFE build, and writes it to `assets/js/qrauth-components.js`. CI runs the same script before the WordPress.org plugin-check job, so the bundle distributed on the directory always matches the published npm release. To rebuild from upstream source instead of the pinned tarball, follow `BUILDING.md` in the upstream library repository above.

== Installation ==

1. Install the plugin from WP-Admin → Plugins → Add New (search for "QRAuth"), then activate it.
2. Create a free account at https://qrauth.io/signup if you do not have one.
3. In the QRAuth dashboard, open **Website Sign-in** and press **Add website**:
    * **Name:** your site name. Visitors see it on the QRAuth sign-in page.
    * **Sign-in return address:** copy it from Settings → QRAuth in WordPress. It looks like `https://your-site.com/wp-login.php?action=qrauth_psl_oidc` and must match exactly.
    * **Kind of site:** Website with a server.
    * **What your site can see:** keep **Full name** and **Email address** ticked. The plugin asks for both.
4. QRAuth shows your **Client ID** and **Client Secret**. Paste both into Settings → QRAuth → Sign in with QRAuth and save. The secret is shown only once; if you lose it, create a new one from the same dashboard page.
5. Open your login page in a private window and press **Sign in with QRAuth**.

Optional: under **Who can sign in**, turn on account creation for visitors who do not have an account yet. Under **Where the button appears**, choose the registration form, WooCommerce forms or the shortcode.

Multilingual sites (WPML, Polylang, Weglot) need only one return address: the plugin uses the language-neutral login URL.

== Frequently Asked Questions ==

= Do my users need to install an app? =

No. On a computer they scan the code with their phone's camera. On a phone they press the button and confirm on the same phone. They confirm with Google, GitHub, Microsoft or Apple on QRAuth's page, so there is nothing to install and no new password.

= How does sign-in work on a phone? =

The visitor presses "Sign in with QRAuth", confirms on QRAuth in the same tab, and lands back on your site signed in. The phone is not asked to scan its own screen; the QR code is only offered for signing in from a second device.

= Should I use Sign in with QRAuth or the embedded widget? =

Use Sign in with QRAuth for every new site. The embedded widget is kept for sites that were set up with it. To switch, add your website under Website Sign-in in the QRAuth dashboard and paste the new Client ID and Client Secret into the Sign in with QRAuth section. Existing users keep their linked accounts. Administrators and Editors who used the widget link their account once from their profile.

= Sign-in does not work. What should I check? =

Settings → QRAuth has a "Sign-in not working?" list. The most common causes:

* QRAuth says "This sign-in link looks wrong": the return address in the QRAuth dashboard does not match the one on the settings page exactly, or the Client ID has a typo.
* Your login page says "QRAuth sign-in failed": Full name or Email address is not ticked for your website in the QRAuth dashboard, or the Client Secret was replaced there.
* "There is no account on this site for your QRAuth identity": no user has that email address and account creation is off.

With `WP_DEBUG_LOG` on, each failed sign-in writes its exact reason to the debug log in a line starting with `[qrauth-psl]`.

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

1. wp-login.php with the "Sign in with QRAuth" button under the password field — drop-in passwordless sign-in, no configuration needed beyond the Client ID.
2. The QRAuth widget active with the animated QR code, session countdown, and "Continue on this device" CTA for users on mobile devices.
3. Settings → QRAuth admin page — Client ID, Client Secret, Tenant URL, auto-provision toggle, default role, allowed scopes, and per-surface enable switches.
4. The QRAuth dashboard at qrauth.io — where site operators generate the Client ID and Client Secret to paste into the plugin settings.

=== Changelog ==
= 0.2.3 =
* Settings → QRAuth is now a step-by-step setup guide for Sign in with QRAuth, with the return address ready to copy, a status banner that says which sign-in method is active, and a "Sign-in not working?" checklist.
* Settings shared by both sign-in methods (who can sign in, where the button appears) have their own sections. The embedded widget's settings are folded under "Older embedded widget (legacy)" and open automatically on sites that still use it.
* Readme: setup instructions and FAQ rewritten around Sign in with QRAuth.

=
= 0.2.2 =
* Fix: the "Sign in with QRAuth" button shown when an OpenID Connect client is configured now carries the QRAuth logo and the same navy style as the embedded widget's button, instead of plain text.

= 0.2.1 =
* Fix: Sign in with QRAuth (OpenID Connect) was refused with "This account cannot sign in right now" on sites running a login reCAPTCHA or 2FA plugin. Those plugins check the password form, which a QRAuth sign-in does not use. QRAuth sign-ins no longer run WordPress's password-form check (`wp_authenticate_user`); inactive accounts are still refused, and account-approval plugins can refuse a QRAuth sign-in through the new `qrauth_psl_oidc_authenticate_user` filter.

= 0.2.0 =
* New: Sign in with QRAuth over OpenID Connect. Create a sign-in client in the QRAuth dashboard, register the redirect URI shown on the settings page, and enter its Client ID and Client Secret under Settings, QRAuth. The sign-in button then sends visitors to QRAuth and back with full-page redirects, so sign-in finishes in the same tab on phones as well as desktops. Sites without an OIDC client keep using the embedded widget unchanged.
* Security: the OIDC flow uses the authorization code flow with PKCE, a single-use state bound to the visitor's browser, and a nonce. The ID token's signature and claims are verified against QRAuth's published keys before anyone is signed in.
* Security: an OIDC sign-in matches an existing account by email only when QRAuth reports the address as verified, and never re-links an account already linked to a different QRAuth identity.
* Security: accounts that can edit other people's content (Editor, Administrator) are never linked by email. Link them once from the user's own profile ("Link your QRAuth account") while signed in with a password. Administrators who signed in with the widget before need to do this once after switching to OIDC.
* Security: OIDC sign-ins respect multisite membership, and start a browser session rather than a "remember me" one. The browser-binding cookie uses the `__Host-` prefix on https, and flow starts are rate limited per IP.
* The OIDC issuer is fixed to https://id.qrauth.io. A self-hosted QRAuth can override it with the `qrauth_psl_oidc_issuer` filter.

= 0.1.24 =
* Security: bumped the vendored QRAuth web component to 0.4.2. Error messages returned by the QRAuth service are now shown as plain text and never interpreted as HTML, and the one-time PKCE verifier used while waiting for approval is sent in a request header instead of the request URL, which keeps it out of server access logs.
* Security: the server-side verification call to QRAuth now sends your site's QRAuth app credentials. QRAuth then confirms a sign-in only when it was approved for your site's own app, so an approval made for a different site cannot be replayed against yours. No settings change is needed: the credentials are the Client ID and Client Secret already saved for the plugin.

= 0.1.23 =
* Compatibility: confirmed working on WordPress 7.0 and bumped "Tested up to" accordingly. Verified on a clean 7.0 install — plugin activation, settings option, and the login-page widget injection all run without fatals or deprecation notices. No source-code changes.

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

= 0.2.3 =
Clearer settings page with a step-by-step Sign in with QRAuth setup guide. No behaviour changes.

= 0.1.24 =
Security hardening: vendored web component 0.4.2 (plain-text error messages, verifier kept out of URLs) and app credentials on the server-side verification call. Recommended for all sites.

= 0.1.23 =
Confirmed compatible with WordPress 7.0. Documentation-only release — no source-code changes.

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
