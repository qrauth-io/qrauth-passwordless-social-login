# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project identity

WordPress plugin — a reference CMS integration for QRAuth (passwordless + social login via the hosted `@qrauth/web-components` widget). The plugin is targeting the WordPress.org plugin directory. It is the first of several planned CMS integrations (Shopify, Drupal, Ghost, …), so patterns here must generalise; avoid WP-only shortcuts unless the equivalent exists across CMSes.

Slug: `qrauth-passwordless-social-login` · PHP ≥ 8.2 · WP ≥ 6.4 · License GPL-2.0-or-later.

## Common commands

```bash
# PHP deps
composer install

# Lint (WordPress Coding Standards via phpcs.xml.dist)
composer lint              # phpcs
composer lint:fix          # phpcbf (auto-fix)

# Tests — unit suite uses Brain\Monkey; integration suite needs a real WP test harness
composer test              # all PHPUnit suites
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite integration
vendor/bin/phpunit --filter SomeTestName tests/Unit/SomeTest.php

# Bring up the WP test library locally (writes to /tmp/wordpress-tests-lib and /tmp/wordpress/)
bin/install-wp-tests.sh wordpress_test root root 127.0.0.1:3306 latest
export WP_TESTS_DIR=/tmp/wordpress-tests-lib   # read by tests/bootstrap.php

# WP.org plugin-check (requires wp-cli + a WP install — usually run in CI)
composer plugin-check

# Vendored JS bundle — fetches @qrauth/web-components from npm, verifies sha512 SRI,
# writes assets/js/qrauth-components.js. Version + integrity pinned in package.json "qrauth".
npm run build:assets
```

## Architecture essentials

- **Bootstrap only at the root.** `qrauth-passwordless-social-login.php` defines `QRAUTH_PSL_*` constants and loads Composer autoload; no business logic. Everything else lives under `src/` (PSR-4 → `QRAuth\PasswordlessSocialLogin\`).
- **Single options row.** All settings live in the `qrauth_psl_settings` array option — never create extra top-level options. User-link state uses `qrauth_psl_user_id` / `qrauth_psl_linked_at` user_meta keys.
- **REST surface.** `POST /wp-json/qrauth-psl/v1/verify` is the one state-changing endpoint. `permission_callback` is `__return_true` *by design* (this is a login endpoint); it instead relies on the `X-WP-Nonce` header, per-IP rate limiting (transients), and the QRAuth signature round-trip.
- **Browser → backend → QRAuth.** The widget emits `qrauth:authenticated`; a tiny inline adapter (`assets/js/qrauth-adapter.js`, <2KB, jQuery-free) forwards `{sessionId, signature}` to the REST route, which calls QRAuth's `/api/v1/auth-sessions/verify-result` via `wp_remote_post` (TLS, 10s timeout). Only `valid=true` + `status=APPROVED` → link/provision + `wp_set_auth_cookie`.
- **Redirect is always `admin_url()`.** Never trust a `redirect` value from QRAuth — that's an open-redirect vector.
- **Role ceiling.** Auto-provisioned users are clamped to `subscriber`/`contributor`/`author`. Reject `editor`/`administrator` in the sanitizer even if the option array is tampered with.
- **Vendored JS is pinned, not bundled.** `bin/fetch-web-components.mjs` downloads the npm tarball, checks sha512 against `package.json#qrauth.webComponentsIntegrity`, extracts `dist/qrauth-components.js`, prefixes a vendored header. Never fetch at runtime; never inline from a CDN. `.gitignore` currently excludes `assets/js/*` — CI rebuilds the bundle before plugin-check, so local changes to the vendored IIFE should not be committed directly (bump version + SRI in `package.json` instead).

## Non-negotiable conventions

Enforced by `phpcs.xml.dist` (WordPress + WordPress-Extra + WordPress-Docs + PHPCompatibilityWP, `testVersion=8.2-`).

- **Prefixes are ruleset-enforced** via `WordPress.NamingConventions.PrefixAllGlobals`:
  - Namespace: `QRAuth\PasswordlessSocialLogin\…`
  - Functions / options / meta / transients / hooks: `qrauth_psl_…`
  - Constants: `QRAUTH_PSL_…`
  - Text domain: `qrauth-passwordless-social-login`
  - REST namespace: `qrauth-psl/v1`
- `declare(strict_types=1);` at the top of every file in `src/`. One class per file. Parameter and return types required.
- PHP 8.2 floor: `match`, union/intersection types, named arguments, constructor property promotion, `readonly` properties, and enums are all available. PHPCompatibilityWP enforces this — don't reach for PHP 8.3+ features without bumping the floor.
- WP 6.4 API floor: anything newer needs a `function_exists` guard.
- Security boilerplate: `defined( 'ABSPATH' ) || exit;` at the top of directly-reachable PHP files; every `echo` goes through `esc_html`/`esc_attr`/`esc_url`/`wp_kses_post`; every user-reachable mutation checks a nonce.
- i18n: every user-facing string through `__()` / `_e()` / `_x()` with the `qrauth-passwordless-social-login` text domain; use `sprintf` with placeholders (no concatenation inside `__`); translator comments on any string with placeholders.
- Logging (`Support\Logger`): only machine codes + a correlation ID. Never log signatures, session IDs (beyond debug), emails, or verifiers. Debug-level output gated on `WP_DEBUG_LOG`.
- JS adapter: ES2020, single-file IIFE, no deps, no jQuery, no `innerHTML` with user data, `fetch` with `credentials: 'same-origin'` + `X-WP-Nonce` header.
- Commits: conventional commits (`feat(rest): …`, `fix(settings): …`, `chore(deps): …`). Branches: `phase/P<N>-<slug>`. One phase = one branch = one PR into `main`.

## Public contract vs. private planning

- **`docs/INTEGRATION-CONTRACT.md`** — *public* cross-CMS spec. Any change to the QRAuth REST surface, widget events, PKCE params, or security properties must be reflected here. Treat edits to this file as public API changes.
- **`SPECS/`** — *gitignored* planning kit (architecture, phased prompts `P0…P5`, TODO, open questions, verification checklist). When starting a new phase, read `SPECS/README.md` first, then the relevant `SPECS/prompts/P<N>-*.md`. Update `SPECS/TODO.md` as you progress; halt at the verification block at the end of each prompt — do not auto-start the next phase.
- If `SPECS/OPEN-QUESTIONS.md` has an entry marked `Blocks: P<current-phase>`, stop and ask before continuing.

## CI (`.github/workflows/ci.yml`)

Four jobs, all green before merge: **lint** (PHPCS on 8.2), **assets** (`npm run build:assets` + verifies IIFE is non-empty), **phpunit** (matrix PHP 8.2/8.3 × WP 6.4/6.5/6.6/6.7/latest = 10 cells), **plugin-check** (WordPress/plugin-check-action, excludes `vendor,node_modules,tests,bin,build`). If CI fails, report and wait — do not push fix-forward commits same-session.
