# QRAuth CMS Integration Contract

Reusable specification for integrating QRAuth passwordless + social login into any CMS, framework, or platform (WordPress, Shopify, Drupal, Ghost, Discourse, PrestaShop, etc.).

This plugin is the **reference implementation**. Every constraint, contract, and security control below is designed to generalize to the other CMS marketplaces without modification to QRAuth's REST surface.

Version: 1.0 (2026-04-20). Pinned against `@qrauth/web-components@0.4.0` and QRAuth API `v1`.

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
   -> 201 { sessionId, token, qrUrl, qrDataUrl, status: 'PENDING', scopes, expiresAt }
3. Browser renders QR for qrUrl OR opens /a/:token in a new tab on mobile.
4. User scans with the QRAuth app OR completes social login on the approval page.
5. Browser polls GET /api/v1/auth-sessions/:id?code_verifier=<verifier>
   (or subscribes to GET /api/v1/auth-sessions/:id/sse?code_verifier=<verifier>)
   -> while PENDING: { status: 'PENDING', user: null, signature: null }
   -> on APPROVED:   { status: 'APPROVED', user: { id, name?, email? }, signature: '<base64 ECDSA-P256>' }
6. <qrauth-login> emits qrauth:authenticated with { sessionId, user, signature }.
7. CMS frontend forwards { sessionId, signature } to its own backend endpoint.
8. CMS backend POST https://qrauth.io/api/v1/auth-sessions/verify-result
      Body: { sessionId, signature }
   -> 200 { valid: true, session: { id, status: 'APPROVED', appName, scopes, user, signature, resolvedAt } }
9. CMS backend links/provisions the local user and creates a session cookie.
```

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
- `redirectUrl` (optional) — where the hosted approval page should send the user after tap-Approve on mobile. **Must match one of the App's registered `redirectUrls`** (same allowlist semantics as OAuth 2.0 redirect URIs).
- `codeChallenge` (required for public clients) — base64url SHA-256 of the verifier.

Response (201):

```json
{
  "sessionId": "cs_...",
  "token": "01HX...",
  "qrUrl": "https://qrauth.io/a/01HX...",
  "qrDataUrl": "https://qrauth.io/a/01HX...",
  "status": "PENDING",
  "scopes": ["identity", "email"],
  "expiresAt": "2026-04-20T12:05:00.000Z"
}
```

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
  "sessionId": "cs_...",
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
  "sessionId": "cs_...",
  "status": "APPROVED",
  "scopes": ["identity", "email"],
  "user": { "id": "u_...", "name": "Ari", "email": "ari@example.com" },
  "signature": "<base64 ECDSA-P256>",
  "expiresAt": "...",
  "scannedAt": "2026-04-20T12:01:30.000Z",
  "resolvedAt": "2026-04-20T12:01:45.000Z"
}
```

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
  "sessionId": "cs_...",
  "signature": "<base64 signature received from the widget>"
}
```

Response (200):

```json
{
  "valid": true,
  "session": {
    "id": "cs_...",
    "status": "APPROVED",
    "appName": "Site Name",
    "scopes": ["identity", "email"],
    "user": { "id": "u_...", "name": "Ari", "email": "ari@example.com" },
    "signature": "<base64 ECDSA-P256>",
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
| `redirect-uri` | URL | no | — | Sent as `redirectUrl` in the session-create body; must match App's `redirectUrls`. Used by the mobile-CTA flow so the hosted approval page returns the user to the CMS tab. |
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

### 6.7 Uninstall / data hygiene

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
