# QRAuth CMS Integration Contract

Reusable specification for integrating QRAuth passwordless + social login into any CMS, framework, or platform (WordPress, Shopify, Drupal, Ghost, Discourse, PrestaShop, etc.).

This plugin is the **reference implementation**. Every constraint, contract, and security control below is designed to generalize to the other CMS marketplaces without modification to QRAuth's REST surface.

Version: 1.1 (2026-04-21). Pinned against `@qrauth/web-components@0.4.0` and QRAuth API `v1`.

**v1.1 changes (all additive — v1.0 integrations remain conformant):**

- Corrected example values for `sessionId` (cuid, no prefix) and `signature` (envelope format `<keyId>:<base64>`) in §3.2, §4.1, §4.2, §4.4 — the v1.0 examples misled integrators into pinning format-specific regexes that reject every real value.
- New §4.5 *URL-param callback* documenting the `?qrauth_session_id=…&qrauth_signature=…` query string qrauth.io appends when redirecting a user back from the hosted approval page after mobile same-device approve. Required for phone sign-in to complete.
- New §6.7 *Same-origin proxy pattern* for third-party-hosted CMSes — direct browser → qrauth.io calls cross a CORS boundary that every third-party-hosted WordPress / Shopify / Ghost install will hit. The proxy pattern used by this plugin is now documented as the recommended mitigation.
- Clarified `redirectUrl` allowlist semantics in §4.1 (normalization ignores trailing slashes, query, fragment — register the base URL once).
- Two additions to the §9 conformance checklist covering the URL-param callback and validator-regex guidance.

---

## 1. What QRAuth provides to CMS integrators

QRAuth is a hosted identity platform. A CMS site that embeds the QRAuth widget gets three capabilities in one drop-in:

1. **QR-based passwordless login** — user scans an animated Living Code with their QRAuth mobile app, approves, and is signed in.
2. **Social login** — Google, GitHub, Microsoft, Apple are brokered by QRAuth's hosted approval page. The CMS site does not register OAuth apps.
3. **Cryptographic assertion** — on approval, QRAuth returns an ECDSA-P256 signature over the session. The CMS backend verifies it against QRAuth's `/verify-result` endpoint before trusting the identity.

The unfair advantage vs. every existing per-provider plugin: the site owner pastes one API key. No Google Cloud project, no GitHub OAuth app, no Apple Developer account.

---

## 2. Parties and trust boundaries

```
+----------+        +-----------------+        +------------------+
| Browser  | <----> | CMS Backend     | <----> | QRAuth API       |
| (web     |  PKCE  | (plugin code)   |  HTTPS | (qrauth.io)      |
|  comp.)  |        |                 |        |                  |
+----------+        +-----------------+        +------------------+
     |                                                  ^
     | scan / approve on phone                          |
     +--------------------------------------------------+
              (QRAuth mobile app -> QRAuth hosted approval page)
```

- **Browser** — runs the `<qrauth-login>` web component. Holds the PKCE `code_verifier` in memory for the lifetime of the session. Emits `qrauth:authenticated` with `{sessionId, user, signature}` on approval.
- **CMS backend** — the plugin/extension code. Receives `{sessionId, signature}` over the CMS's own REST/AJAX transport (e.g. `wp-admin/admin-ajax.php`, a Shopify App Proxy endpoint, a Ghost adapter). Verifies cryptographically with QRAuth, then provisions/links the local user.
- **QRAuth API** — the source of truth. Issues the session, brokers social logins, produces the ECDSA signature on approval, verifies signatures on demand.

The CMS backend does **not** hold a QRAuth client secret in the PKCE flow (§3.1). The public `clientId` is the only secret material that ships to the browser.

---

## 3. Authentication flow (PKCE public client)

The embedded widget uses the OAuth 2.0 PKCE pattern. The CMS backend is never involved until the final cryptographic verification step.

### 3.1 Actors

- **Public client**: the `<qrauth-login>` web component running in the user's browser. Identified by `X-Client-Id` only — **no client secret**.
- **Hosted approval page**: `https://qrauth.io/a/:token` — renders the QR, handles social-login buttons, collects the user's Approve/Deny decision.
- **Mobile app**: the QRAuth phone app that scans the Living Code and approves the session.

### 3.2 Sequence

```
1. Browser generates code_verifier (32 bytes base64url) + code_challenge (SHA-256 of verifier, base64url).
2. Browser POST /api/v1/auth-sessions/
      Headers: X-Client-Id: <public clientId>
      Body:    { scopes: [...], redirectUrl?: string, codeChallenge, codeChallengeMethod: 'S256' }
   -> 201 { sessionId: 'clq9r8t0u0000abc123def456',    // cuid — opaque, no prefix
           token: '01HX...',
           qrUrl, qrDataUrl, status: 'PENDING', scopes, expiresAt }
3. Browser renders QR for qrUrl OR opens /a/:token in a new tab on mobile.
4. User scans with the QRAuth app OR completes social login on the approval page.
5. Browser polls GET /api/v1/auth-sessions/:id?code_verifier=<verifier>
   (or subscribes to GET /api/v1/auth-sessions/:id/sse?code_verifier=<verifier>)
   -> while PENDING: { status: 'PENDING', user: null, signature: null }
   -> on APPROVED:   { status: 'APPROVED',
                       user: { id, name?, email? },
                       signature: '<keyId>:<base64sig>'   // envelope — see §4.2 and §4.4
                     }
6. <qrauth-login> emits qrauth:authenticated with { sessionId, user, signature }.
7. CMS frontend forwards { sessionId, signature } to its own backend endpoint.
8. CMS backend POST https://qrauth.io/api/v1/auth-sessions/verify-result
      Body: { sessionId, signature }          // signature carries the <keyId>: envelope verbatim
   -> 200 { valid: true, session: { id, status: 'APPROVED', appName, scopes, user, signature, resolvedAt } }
9. CMS backend links/provisions the local user and creates a session cookie.
```

On mobile same-device the user leaves the CMS tab to approve on qrauth.io, and the original tab is usually suspended before polling completes. To finish the flow, qrauth.io appends `qrauth_session_id` and `qrauth_signature` query params to the configured `redirect-uri` on return — the consumer picks them up on landing and runs step 7 onward. See §4.5.

### 3.3 PKCE parameters

| Field | Value |
|---|---|
| `code_verifier` | 32 random bytes, base64url-encoded (no padding) |
| `code_challenge` | `base64url(SHA-256(code_verifier))` |
| `code_challenge_method` | `S256` (only value supported) |

Public clients **must** send `codeChallenge`. Server returns 400 otherwise.

Only `S256` is supported. `plain` is rejected.

### 3.4 Scopes

| Scope | Meaning |
|---|---|
| `identity` | Returns `user.name` on `/:id` and `/verify-result` |
| `email` | Returns `user.email` on `/:id` and `/verify-result` |
| `organization` | (reserved — org metadata, unused in current widget flows) |

Requested scopes must be a subset of the App's `allowedScopes` registered on the QRAuth dashboard. Disallowed scopes cause 400.

### 3.5 Session lifetime

- Sessions expire 5 minutes after creation (`AUTH_SESSION_EXPIRY_SECONDS = 300`).
- Statuses: `PENDING → SCANNED → APPROVED | DENIED | EXPIRED`.
- The component emits `qrauth:scanned`, `qrauth:authenticated`, `qrauth:denied`, or `qrauth:expired` for each terminal event.

---

## 4. REST contract

Base URL: `https://qrauth.io` (overridable via the component's `base-url` attribute for self-hosted QRAuth deployments).

All endpoints consume and produce `application/json; charset=utf-8` unless noted.

### 4.1 `POST /api/v1/auth-sessions/`

Create a new auth session. Called from the browser (public client, PKCE).

| | |
|---|---|
| Auth | `X-Client-Id: <clientId>` (header) |
| Rate limit | `rateLimitAuth` |

Request body:

```json
{
  "scopes": ["identity", "email"],
  "redirectUrl": "https://site.example.com/wp-login.php?qrauth=complete",
  "codeChallenge": "<base64url SHA-256 of code_verifier>",
  "codeChallengeMethod": "S256"
}
```

- `scopes` (optional, default `["identity"]`) — must be subset of the App's `allowedScopes`.
- `redirectUrl` (optional) — where the hosted approval page should send the user after tap-Approve on mobile. **Must match one of the App's registered `redirectUrls`**. The server-side comparison normalises the URL before matching: trailing slashes are ignored, and the query string + fragment are stripped before comparison. Register the canonical base URL once (e.g. `https://site.example.com/wp-login.php`) and any runtime variant with query params still matches.
- `codeChallenge` (required for public clients) — base64url SHA-256 of the verifier.

Response (201):

```json
{
  "sessionId": "clq9r8t0u0000abc123def456",
  "token": "01HXABC123DEF456GHI789JKL",
  "qrUrl": "https://qrauth.io/a/01HXABC123DEF456GHI789JKL",
  "qrDataUrl": "https://qrauth.io/a/01HXABC123DEF456GHI789JKL",
  "status": "PENDING",
  "scopes": ["identity", "email"],
  "expiresAt": "2026-04-20T12:05:00.000Z"
}
```

`sessionId` is a **cuid** — opaque, no `cs_` or other prefix. Treat it as a 24–32 character base64url-alphabet string. Input-validation regexes on the consumer side should use an opaque-ID allowlist (e.g. `^[A-Za-z0-9_-]{8,128}$`), not a UUID or prefix-specific pattern, to avoid breaking when the monolith switches ID formats (has happened; will happen again).

Errors:

| Status | Condition |
|---|---|
| 400 | Public client without `codeChallenge` |
| 400 | `codeChallengeMethod` not `S256` |
| 400 | `redirectUrl` not registered on the App |
| 400 | `scopes` contains values outside `App.allowedScopes` |
| 401 | Missing/invalid `X-Client-Id` |
| 429 | Org has exceeded its `authSessions` quota |

### 4.2 `GET /api/v1/auth-sessions/:id`

Poll for session status. Called from the browser.

| | |
|---|---|
| Auth | `X-Client-Id: <clientId>` (header) |
| PKCE gate | When `status === 'APPROVED'` on a PKCE session accessed by a public client, the caller **must** pass `?code_verifier=<verifier>` to receive `user` and `signature`. Without it, those fields are returned as `null` and the rest of the session status is still available. |

Query params:

- `code_verifier` (optional but required to unlock approved results for PKCE public clients)

Response (200) — pending:

```json
{
  "sessionId": "clq9r8t0u0000abc123def456",
  "status": "PENDING",
  "scopes": ["identity", "email"],
  "user": null,
  "signature": null,
  "expiresAt": "2026-04-20T12:05:00.000Z",
  "scannedAt": null,
  "resolvedAt": null
}
```

Response (200) — approved, verifier passed:

```json
{
  "sessionId": "clq9r8t0u0000abc123def456",
  "status": "APPROVED",
  "scopes": ["identity", "email"],
  "user": { "id": "cluser9r8t0u0000xyz789", "name": "Ari", "email": "ari@example.com" },
  "signature": "cmkey3x4abc123xyz789:MEUCIQDabc+123/def456GHIjkl789MNOpqrstuvwxYZ01234567ABCdef==",
  "expiresAt": "...",
  "scannedAt": "2026-04-20T12:01:30.000Z",
  "resolvedAt": "2026-04-20T12:01:45.000Z"
}
```

**`signature` is an envelope**, not raw base64. Format: `<keyId>:<base64sig>`, where `<keyId>` identifies the signing key stored on the org (so `/verify-result` can look up the right public key) and `<base64sig>` is the DER-encoded ECDSA-P256 signature over the session's canonical form, encoded in standard base64 (alphabet `A-Za-z0-9+/=`, not base64url). The colon separator is load-bearing: strip or mangle it and `/verify-result` can't locate the key.

Input-validation on the consumer side should use a union alphabet that accepts the colon, both base64 variants, and a reasonable minimum length — e.g. `^[A-Za-z0-9+/=_:.-]{24,}$`. A regex pinned to base64url alphabet only (`[A-Za-z0-9_-]`) or to raw base64 without the colon rejects every real signature.

Errors:

| Status | Condition |
|---|---|
| 401 | Missing/invalid `X-Client-Id` |
| 403 | `code_verifier` provided but does not hash to the stored `code_challenge` |
| 404 | Session not found OR App does not own the session |

### 4.3 `GET /api/v1/auth-sessions/:id/sse`

Server-Sent Events stream for the same session. Lower-latency alternative to polling.

| | |
|---|---|
| Auth | `X-Client-Id: <clientId>` (header) |
| PKCE gate | **Public clients must pass `?code_verifier=<verifier>` upfront.** Missing verifier → 403. Invalid verifier → 403. This is stricter than the polling endpoint because SSE cannot upgrade mid-stream (AUDIT-FINDING-018). |

Response: `text/event-stream`. Events push the same session-state JSON on each transition.

### 4.4 `POST /api/v1/auth-sessions/verify-result`

Cryptographic verification endpoint. **Called from the CMS backend**, not the browser.

| | |
|---|---|
| Auth | None (no app credentials required) |
| Rate limit | `rateLimitPublic` |

Request body:

```json
{
  "sessionId": "clq9r8t0u0000abc123def456",
  "signature": "cmkey3x4abc123xyz789:MEUCIQDabc+123/def456GHIjkl789MNOpqrstuvwxYZ01234567ABCdef=="
}
```

Both values flow through from the widget verbatim — the CMS backend must **not** modify, decode, or strip the `<keyId>:` prefix from `signature`. The server uses the prefix to look up the correct public key.

Response (200):

```json
{
  "valid": true,
  "session": {
    "id": "clq9r8t0u0000abc123def456",
    "status": "APPROVED",
    "appName": "Site Name",
    "scopes": ["identity", "email"],
    "user": { "id": "cluser9r8t0u0000xyz789", "name": "Ari", "email": "ari@example.com" },
    "signature": "cmkey3x4abc123xyz789:MEUCIQDabc+123/def456GHIjkl789MNOpqrstuvwxYZ01234567ABCdef==",
    "resolvedAt": "2026-04-20T12:01:45.000Z"
  }
}
```

- `valid === true` **only when** the submitted signature verifies against the org's public ECDSA-P256 key over the session's canonical form **and** `session.status === 'APPROVED'` (AUDIT-FINDING-002). This is real crypto verification, not a bearer-token compare.
- Scope gating still applies: `user.name` appears only if `scopes` includes `identity`; `user.email` only if `scopes` includes `email`.

Errors:

| Status | Condition |
|---|---|
| 400 | Missing `sessionId` or `signature` |
| 404 | Session not found |

**The CMS backend must treat `valid: false` as "reject this login". There is no ambiguity.**

### 4.5 URL-param callback (mobile same-device flow)

Required for phone sign-in. Desktop sign-in works without this surface because polling stays alive in the same tab; mobile same-device does not (the original tab is usually suspended while the user authenticates on the qrauth.io tab).

**Mechanism.** If the widget was created with a `redirect-uri` attribute and the value matches the App's registered `redirectUrls` allowlist, the hosted approval page at `qrauth.io/a/:token` redirects the user back to that URL after Approve. qrauth.io appends two query params to the redirect:

```
https://site.example.com/wp-login.php?qrauth_session_id=clq9r8t0u0000abc123def456&qrauth_signature=cmkey3x4abc123xyz789%3AMEUCIQDabc%2B123%2Fdef456GHIjkl789MNOpqrstuvwxYZ01234567ABCdef%3D%3D
```

Note: the signature's `+`, `/`, `=`, and `:` are percent-encoded in the URL. Consumers that read via `URLSearchParams.get()` get the decoded value automatically; consumers that read `window.location.search` manually must decode first.

**Consumer obligations.**

1. On every page that could be a `redirect-uri` landing destination, check the URL for `qrauth_session_id` and `qrauth_signature` early in the bootstrap (before any auth guard redirect would otherwise fire).
2. If both are present, run the standard verification path (POST to the CMS backend's verify endpoint with `{ sessionId, signature }`, which in turn calls `/verify-result` — see §4.4).
3. **Scrub the params from the URL unconditionally** via `history.replaceState()` before or immediately after the POST, so a page refresh can't replay a consumed signature. The server rejects consumed signatures, but the URL bar shouldn't show auth material.
4. Complete the sign-in (set the session cookie / issue the JWT / whatever the CMS's convention is), then redirect to the post-login destination.

**Why this is non-optional for mobile.** `window.open(url, '_blank', 'noopener')` on mobile Safari, mobile Chrome, and most mobile browsers tends to replace the current tab rather than open a new one. The WP tab that was polling for APPROVED is then either suspended or discarded entirely. By the time the user returns from qrauth.io, there's no live listener to fire `qrauth:authenticated` — the URL-param path is the only reliable completion route.

**Parameter names are fixed.** `qrauth_session_id` and `qrauth_signature`. Consumers must use these exact names; they are part of this contract and are not configurable per-app.

---

## 5. Web Component contract

The `<qrauth-login>` element ships in `@qrauth/web-components` and is vendored by this plugin at `assets/js/qrauth-components.js` (IIFE build; registers custom elements on load).

### 5.1 Attributes

| Attribute | Type | Required | Default | Notes |
|---|---|---|---|---|
| `tenant` | string | yes | — | Your App's public `clientId` |
| `base-url` | string | no | `https://qrauth.io` | Override for self-hosted QRAuth |
| `theme` | `light` \| `dark` | no | `light` | |
| `scopes` | space-separated | no | `identity` | Subset of the App's `allowedScopes` |
| `redirect-url` | URL | no | — | Client-side redirect after widget fires `authenticated` |
| `redirect-uri` | URL | no | — | Sent as `redirectUrl` in the session-create body; must match App's `redirectUrls` allowlist (§4.1 notes the normalization semantics). Triggers the URL-param callback flow — consumer must handle `qrauth_session_id` + `qrauth_signature` on the landing page per §4.5. Required in practice for mobile same-device sign-in. |
| `on-auth` | string | no | — | Name of a global `window[...]` callback invoked with the event payload |
| `display` | `button` \| `inline` | no | `button` | `button` opens a modal; `inline` renders the QR directly |
| `animated` | boolean attribute | no | absent | Enables subtle pulse animation on the QR |
| `force-mode` | `mobile` \| `desktop` \| `auto` | no | `auto` | Override the automatic mobile detection |
| `mobile-fallback-only` | boolean attribute | no | absent | Keep QR-first body on every device (disables the mobile CTA swap) |

### 5.2 Events (all `bubbles: true, composed: true`)

| Event | `detail` | When |
|---|---|---|
| `qrauth:scanned` | `{ sessionId, status: 'SCANNED', ... }` | After the user scans but before Approve/Deny |
| `qrauth:authenticated` | `{ sessionId, user, signature }` | On APPROVED terminal state |
| `qrauth:denied` | (none) | On DENIED terminal state |
| `qrauth:expired` | (none) | On 5-minute timeout |
| `qrauth:error` | `{ message }` | On transport / validation error |

`user` is the server response object (shape depends on requested scopes — see §4.2).

### 5.3 Theming

CSS custom properties, inherited from the host document:

- `--qrauth-primary`, `--qrauth-primary-hover`, `--qrauth-bg`, `--qrauth-text`, `--qrauth-border`, `--qrauth-radius`, `--qrauth-font`.

Full list documented in `@qrauth/web-components/README.md`.

---

## 6. Minimum consumer checklist

Every CMS integration **must** implement all of the following. None are optional.

### 6.1 Configuration surface

- A settings/admin page that accepts a single field: QRAuth **Client ID** (public, safe to expose in HTML source).
- A link to the QRAuth dashboard (`/dashboard/apps/create`) with inline instructions for creating an App and registering the CMS site URL as a `redirectUrl`.
- An optional **API Base URL** field for self-hosted QRAuth deployments (default to `https://qrauth.io`).

### 6.2 Frontend

- Render `<qrauth-login tenant="<clientId>" ...>` on the appropriate authentication surface (login page, registration page, account linking page).
- Enqueue the vendored `assets/js/qrauth-components.js` IIFE — **do not load from a CDN**. Vendoring is required for WP.org compliance and supply-chain integrity.
- Bind a listener for `qrauth:authenticated`; forward `{ sessionId, signature }` to the CMS backend over the platform's own AJAX/REST transport. Include a CSRF nonce per the platform's conventions.
- If `redirect-uri` is set on the widget, also handle the URL-param callback per §4.5: on every page that could be a landing destination, check the URL for `qrauth_session_id` + `qrauth_signature`, scrub them via `history.replaceState()`, and complete the verification via the same transport. Required for mobile same-device sign-in.

### 6.3 Backend verification

On receiving `{ sessionId, signature }` the backend **must**:

1. Validate the CSRF/nonce token for the current request.
2. POST to `https://<base-url>/api/v1/auth-sessions/verify-result` with `{ sessionId, signature }`.
3. **Reject** if the HTTP call fails, if `valid !== true`, or if `session.status !== 'APPROVED'`.
4. Read `session.user.email` as the canonical identifier for user linking (see §7).
5. Log the outcome to the platform's audit trail with at least: `sessionId`, `user.id`, `resolvedAt`, verification result.

The backend **must not** trust anything in the request body beyond what the verify-result call returns. The signature is the only authority.

### 6.4 User linking / provisioning

See §7 for full rules. At minimum:

- Match incoming `user.email` case-insensitively against existing local accounts.
- If matched, create a local session for that account.
- If unmatched and provisioning is enabled (per site config), create a new local account from the returned profile.
- If unmatched and provisioning is disabled, show a clear error and do not create a session.

### 6.5 Security controls

- Always use HTTPS for the CMS backend endpoint that receives `{ sessionId, signature }`.
- Treat the QRAuth `clientId` as public (safe to embed in HTML); treat no other value as public.
- Rate-limit the CMS backend endpoint (per-IP and per-user if logged in).
- Never persist the raw `signature` — it's single-use verification material, not a session token.
- Never log the PKCE `code_verifier` or `code_challenge`.

### 6.6 Pinned assets

- Pin the vendored web-components bundle by exact version and SRI hash (sha512). Upgrade both together. The reference implementation does this in `package.json` under the `qrauth` key and verifies in `bin/fetch-web-components.mjs`.

### 6.7 Same-origin proxy for third-party-hosted CMSes

The sequence in §3.2 assumes the browser can call `https://qrauth.io/api/v1/auth-sessions` directly. In production on a third-party-hosted CMS (arbitrary WordPress site, arbitrary Shopify storefront, arbitrary Ghost blog) this **fails by default**: the browser treats every consumer site as a cross-origin request against qrauth.io and either blocks the preflight (if CORS headers aren't permissive) or the response (if it carries credentials). Requiring every CMS admin to get their origin added to a global CORS allowlist on qrauth.io does not scale.

The standard mitigation is a **same-origin proxy on the CMS backend**. The CMS plugin exposes three routes under its own REST namespace — all three are simple passthroughs, no business logic:

```
POST /<cms-namespace>/api/v1/auth-sessions
  -> server-to-server POST https://qrauth.io/api/v1/auth-sessions
     forward the browser JSON body verbatim, inject the App's
     Basic auth + X-Client-Id server-side, strip Set-Cookie from
     upstream, return upstream status + JSON verbatim.

GET /<cms-namespace>/api/v1/auth-sessions/<id>
  -> server-to-server GET https://qrauth.io/api/v1/auth-sessions/<id>?<query>
     same injection + strip rules. The code_verifier query param
     must flow through.

GET /<cms-namespace>/a/<token>
  -> 302 redirect to https://qrauth.io/a/<token>
     hosted approval page must load on qrauth.io (WebAuthn RP-ID
     constraint); a JSON proxy here would not work. Bare 302 keeps
     the mobile same-device flow intact.
```

The widget's `base-url` attribute is then set to the CMS's own REST root (e.g. `https://site.example.com/wp-json/<cms-namespace>/api/v1`), so every browser call stays same-origin and CORS never fires. Consumer setup is unchanged — just the origin the browser talks to.

The reference implementation (`src/Rest/AuthSessionProxyController.php`) covers all three routes and the header allowlist rules (forward only `Content-Type`; strip browser-sent `Cookie`, `Authorization`, `X-Client-Id` — the proxy injects its own from stored credentials). Integrations for other CMSes should mirror this structure.

Storing the App's Client Secret server-side for the Basic-auth injection requires the consumer site to hold a secret, which is one boundary looser than the raw PKCE public-client model. Treat `client_secret` with the same operational trust level as any other CMS-stored API key (WordPress `wp_options`, Shopify metafield, Ghost secret).

### 6.8 Uninstall / data hygiene

- On plugin uninstall, delete all plugin-owned options / settings rows.
- Do **not** delete user accounts created via QRAuth provisioning — leave them owned by the platform.
- Provide an option to "unlink QRAuth" from a user profile; this must not delete the local user.

---

## 7. User linking & provisioning

The canonical identifier from QRAuth is `user.id` (opaque, stable per QRAuth user) plus `user.email` (mutable, but the practical linking key for most CMSes).

### 7.1 Linking rules

1. **Match on `user.id` first** if the CMS already has a stored mapping (`qrauth_user_id` → local user).
2. If no mapping exists and `email` scope was granted, match on `user.email` (case-insensitive, trimmed).
3. If matched, store the `user.id` ↔ local-user mapping on first successful link.
4. If unmatched and auto-provisioning is **enabled**: create the local account and persist the mapping in the same transaction.
5. If unmatched and auto-provisioning is **disabled**: deny the sign-in with a clear message and a link to "Sign up first".

### 7.2 Role / permission defaults

- Auto-provisioned accounts get the CMS's lowest-privilege role (`subscriber` on WordPress, `customer` on Shopify, `member` on Ghost).
- **Never** auto-provision administrative roles.
- Role promotion must remain a manual decision by a local admin.

### 7.3 Email verification

- If QRAuth granted the `email` scope, the email has been verified by QRAuth's social provider or by passkey enrollment. The CMS may mark the email verified locally.
- If `email` scope was not granted, do not mark the email verified.

### 7.4 Unlinking

- Any user may unlink QRAuth from their local profile. This removes the mapping but preserves the account.
- If QRAuth was the user's **only** sign-in method, the CMS must require a local password reset flow **before** unlinking succeeds (otherwise the user locks themselves out).

---

## 8. Threat model (what the contract protects against)

| Threat | Control |
|---|---|
| Attacker steals `clientId` from HTML source | `clientId` is public by design; PKCE binds the session to the original browser via `code_verifier` |
| Attacker observes a `signature` value and replays it | `/verify-result` performs real ECDSA verification; no bearer-token semantics (AUDIT-FINDING-002) |
| Attacker holding only `clientId` + `sessionId` subscribes to SSE and harvests the user | SSE requires `code_verifier` upfront for PKCE sessions (AUDIT-FINDING-018) |
| Attacker crafts a session with `redirectUrl=evil.com` and tricks user into approving | `redirectUrl` must appear in the App's pre-registered `redirectUrls` allowlist |
| CMS admin leaks Client Secret | PKCE flow never sees a client secret; none exists on the consumer side |
| CDN compromise replaces the web-component bundle | Plugin ships a vendored copy pinned by sha512 SRI; no CDN load at runtime |
| Attacker POSTs `{sessionId, signature=''}` to verify-result | Rejected at the crypto layer; `valid: false` |
| Attacker replays an old APPROVED signature to log in as the user again | Verify-result requires `status === 'APPROVED'`; a revoked/expired session fails |

### 8.1 Out of scope for this contract

- Account recovery if the user loses their QRAuth device (handled by QRAuth's own recovery flows).
- Session revocation after login — once the CMS issues its own session cookie, that session's lifecycle is the CMS's responsibility.
- Cross-device session migration (handled by QRAuth mobile app UX).

---

## 9. Conformance checklist

A plugin/extension conforms to this contract if and only if:

- [ ] Uses `X-Client-Id` (never a client secret) from the browser.
- [ ] Generates `code_verifier` client-side, stores it in memory for the session's lifetime only.
- [ ] Passes `codeChallenge` + `codeChallengeMethod: 'S256'` on session create.
- [ ] Passes `?code_verifier=` on polling/SSE when retrieving approved results.
- [ ] Forwards `{ sessionId, signature }` from `qrauth:authenticated` to the CMS backend.
- [ ] Handles the URL-param callback on the configured `redirect-uri` landing page: reads `qrauth_session_id` + `qrauth_signature` from the query string, scrubs via `history.replaceState()`, runs verification via the same path as the event handler. Required for mobile same-device sign-in (§4.5).
- [ ] Validates incoming `sessionId` / `signature` with opaque-alphabet allowlists, not UUID- or base64url-specific regexes. Suggested patterns: `sessionId ^[A-Za-z0-9_-]{8,128}$`; `signature ^[A-Za-z0-9+/=_:.-]{24,}$`. Semantic validation is upstream at `/verify-result` — the consumer regex is a "not-obviously-malformed" gate only.
- [ ] Calls `/api/v1/auth-sessions/verify-result` from the backend and rejects on `valid: false` or `status !== 'APPROVED'`.
- [ ] Uses `user.email` (when scope granted) as the linking key, matched case-insensitively.
- [ ] Stores a `user.id` ↔ local-user mapping on first successful link.
- [ ] Auto-provisions to the lowest-privilege role only.
- [ ] Vendors the web-components bundle locally, pinned by version and sha512 SRI hash.
- [ ] Respects the CMS's own CSRF/nonce conventions on the receiving backend endpoint.
- [ ] Does not persist the raw `signature`.
- [ ] Provides an admin settings surface for Client ID + optional base URL.
- [ ] Provides an unlink flow that preserves the local account.
- [ ] Does not delete user accounts on plugin uninstall.

---

## 10. Versioning

- **This contract** is versioned (`v1.0`). Any breaking change to the REST surface or web-component event payloads increments the major.
- **The QRAuth API** is `v1`. Additive changes (new optional fields) are non-breaking.
- **The web-components bundle** is semver'd independently (`@qrauth/web-components`). Upgrade the pinned version + SRI hash in `package.json` together.

---

## 11. Reference implementation

The WordPress plugin in this repository (`qrauth-passwordless-social-login`) is the reference implementation. When in doubt about a detail of the contract, the reference implementation's behavior is authoritative.

Equivalent future marketplaces (Shopify, Drupal, Ghost, Discourse, PrestaShop) must match the conformance checklist in §9. Deviations must be documented in the integration's own repo and justified against the threat model in §8.
