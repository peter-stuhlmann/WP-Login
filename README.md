# Portier

A modern, accessible custom login for WordPress: own login address, a beautiful mobile-first
design, secure by default – and it keeps working with the plugins that hook into the core login.

> Status: **1.0.0** – all planned phases are done (core, 2FA, magic link, social login, templates
> and design editor, passkeys). The wordpress.org readme is `readme.txt`.

## Features

- **Own login address** (`/login/` by default, configurable), works with plain permalinks,
  multisite and language prefixes (Polylang/WPML).
- **Eight templates** (Aurora, Pure, Studio, Midnight, Journal, Bold, Cloud, Enterprise) with
  self-hosted fonts and a **live preview** in the Design tab (device switcher, all screens).
  Own colours, radius, font and custom CSS (sanitised, versioned) with a live WCAG contrast check.
  Background image with overlay transparency, focus point, dark-mode image and phone option.
- **Login in one or two steps** (setting): both fields on one screen, or e-mail first and the
  password on its own screen – without revealing whether an account exists.
- **All screens**: log in, "already logged in", forgot password, check your inbox, set new
  password, expired link, registration, registration closed.
- **Progressive enhancement**: every screen is a plain HTML form that works without JavaScript.
  A 4 KB script adds smooth in-place transitions (View Transitions API), focus management,
  "show password" and a Caps Lock hint.
- **Secure by default**
  - brute-force protection on *every* login path (this page, `wp-login.php`, XML-RPC, other
    login forms): per IP with doubling lock times, plus a per-account limit; counters are
    updated under a lock, so parallel attempts all count,
  - CSRF protection for logged-out forms (signed double-submit token),
  - no account enumeration (identical answers for unknown accounts),
  - reset keys never stay in the URL; all sessions end after a password reset,
  - bot traps for registration (honeypot + signed time trap),
  - security headers (CSP `frame-ancestors`/`form-action`, no caching, `noindex`),
  - optional XML-RPC switch, client IP from a trusted proxy header only if configured
    (`X-Forwarded-For`: the last public address – the one the proxy added),
  - new ways into an account (passkey, social connection, 2FA setup, recovery codes) need a
    login from the last 15 minutes (`portier_recent_login_seconds`); the owner is e-mailed,
  - login link, passkey and social login refuse accounts marked as spam on a network
    (`portier_passwordless_login_allowed` for more),
  - e-mails that only exist for registered accounts are sent after the response (PHP-FPM,
    LiteSpeed), so its timing does not reveal accounts,
  - multisite: only network administrators change the security settings.
- **Two-factor authentication** (TOTP, RFC 6238) with any authenticator app:
  - off / optional / required for chosen roles – required roles are guided through the setup
    (QR code + manual key, then 10 recovery codes to copy, download or print) at their next login,
  - one real `autocomplete="one-time-code"` input (paste, autofill and screen readers work), drawn
    as six boxes; replay protection, 5 attempts per login and 10 per account and hour (then a
    doubling pause and an e-mail to the owner), optional "trust this device" (0–90 days),
  - secrets encrypted at rest (libsodium; key from `PORTIER_ENCRYPTION_KEY` or the salts),
    recovery codes stored as keyed hashes,
  - enforced on *every* password login path (`wp-login.php`, XML-RPC); application passwords
    are separate credentials and pass; a new password (reset or profile) forgets trusted devices,
  - fails closed: a secret that can no longer be decrypted (changed salts or key) keeps 2FA
    required until an administrator resets it,
  - profile section: set up, new recovery codes, turn off (needs the password); admins can reset.
- **Magic link** (optional): one-time login link by e-mail, 5–60 minutes, only the newest link
  is valid. The link opens a confirmation page (button = POST), so e-mail scanners cannot log
  anybody in; the token leaves the URL immediately. 2FA still applies afterwards.
- **Passkeys** (WebAuthn, phishing-resistant):
  - people add passkeys in their profile (name, last use, synced or not; rename, delete); the
    account owner gets an e-mail for every new passkey,
  - login with the "Log in with a passkey" button (waiting screen with status for screen
    readers, "Try again", "Choose another method"), by **autofill** in the e-mail field
    (`autocomplete="username webauthn"`, conditional mediation) or from the second-factor screen,
  - a passkey replaces password *and* second factor (the device verified the person),
  - verified without third-party libraries: CBOR/COSE parsing, ES256, EdDSA (Ed25519) and RS256
    (≥ 2048 bit) signatures, origin and RP ID checks (phishing), user presence + verification,
    signature counter (cloned keys), single-use challenges bound to the browser or session,
    rate limits; only public keys are stored, behind a random user handle,
  - needs HTTPS (`http://localhost` works for development).
- **Social login** with Google, LinkedIn (OpenID Connect) and Discord (OAuth 2.0), more via the
  `portier_oauth_providers` filter:
  - authorization code flow with `state` bound to the browser (cookie, single use, 10 minutes),
    PKCE (S256) where supported, OIDC `nonce`; ID tokens are verified (RS256 signature against the
    provider's JWKS, issuer, audience/azp, expiry) – everything else is rejected,
  - accounts are matched by the provider's stable user id; an existing account is linked only
    when the provider *verified* the same e-mail address (or never, per provider); new accounts
    only with open registration and when allowed per provider,
  - administrators are blocked from social login by default (configurable roles); 2FA still applies,
  - secrets encrypted at rest or kept in `wp-config.php` (`PORTIER_GOOGLE_CLIENT_SECRET`, …) and
    never sent back to the browser; redirect URIs `/login/oauth/<provider>/` with copy button,
  - users connect and disconnect providers in their profile,
  - **"Test connection"** in the admin: is the provider reachable, does it accept client ID and
    secret (a token request with a code that cannot be valid tells `invalid_client` apart),
  - the order of the buttons below the form (passkey and providers) is set by **drag and drop or
    arrow keys** (handle with screen reader announcements) and previewed in the admin.
- **Accessible** (WCAG 2.2 AA): visible labels, error summary with jump links, `aria-invalid`
  / `aria-describedby`, live region, skip link, 24 px+ targets, 16 px inputs, reduced motion,
  forced colours (Windows high contrast), dark mode.
- **Multilingual**: English source, German (`de_DE`, informal) and German formal
  (`de_DE_formal`); language switcher for all installed languages; RTL stylesheet.
- **Admin** as designed in Figma ("06 Admin"): Login methods (incl. provider set-up and a live
  preview of the buttons), Security, Registration and Advanced tabs with matching
  wp-admin submenu entries, master switch, change counter and a sticky save bar on mobile.
  Small own React components (ARIA tabs, `role="switch"`, native radios/selects).
- **Compatible**: core hooks keep firing (`login_form`, `authenticate`, `login_redirect`,
  `register_post`, `validate_password_reset`, …), so captcha/SSO/security plugins keep working.
  Logout, password protected posts, privacy requests and the session-expired modal stay in core.

## Requirements

PHP 8.1+, WordPress 6.5+. No runtime Composer or npm dependencies.

## Encryption key (recommended)

2FA secrets are encrypted. Define your own key in `wp-config.php`, so a database dump alone
reveals nothing (otherwise the key is derived from the WordPress salts):

```php
define( 'PORTIER_ENCRYPTION_KEY', 'a-long-random-string' );
```

Changing the key or the salts later makes existing secrets unreadable – 2FA then stays required
(it never switches itself off) until an administrator resets it in the user's profile.

## Audit log

Portier writes an audit log file – no UI, meant to be read on the server:

```
2026-10-23 14:05:31 +02:00 | Ada Admin <ada@example.org> #2 Administrator | 203.0.113.9 · Chrome on Windows | Admin | settings.changed | Login with Google enabled
2026-10-23 14:07:02 +02:00 | – | 198.51.100.4 · Safari on iOS | Login page | login.failed | Login failed for “lena” (wrong login or password)
2026-10-23 14:07:40 +02:00 | Lena Berger <lena@example.org> #5 Editor | 198.51.100.4 · Safari on iOS | Login page | login.success | Logged in with passkey
```

Fields: time (seconds, time zone) · person (name, e-mail, ID, role) · IP address (trusted proxy
header if configured) and browser · where (admin, profile, login page, WP-CLI …) · event code ·
what, in the site language; on multisite the site after the time. Recorded: every setting
(old → new; secrets and CSS only as "changed"), registration open/closed and default role, 2FA
set up/off/reset, new recovery codes, passkeys added/renamed/deleted, social accounts
connected/disconnected, logins with their method, failed logins (the entered login name only if
it belongs to an account – people sometimes type their password there), pauses after too many
attempts, wrong 2FA codes and their pauses, failed passkey and social logins, logouts, login links, password resets, new
accounts, plugin activated/deactivated. Control characters are removed, so entered values cannot
forge lines.

Location: `wp-content/portier-logs/portier-YYYY-MM-<token>.log` (closed with `.htaccess`,
`web.config` and `index.php`; the random token hides the names on nginx) or `PORTIER_LOG_DIR`.
Kept 12 months (`portier_audit_log_retention_months`); removed on uninstall. Other plugins can
listen to the same `portier_*` actions.

## Passkeys

On by default (*Login methods → Passkeys*) as soon as the site runs on HTTPS. Everybody adds
their passkeys under *Users → Profile → Passkeys*. Failed passkey logins fire
`portier_passkey_login_failed` (reason, technical details) for logging.

## Social login set-up

1. Create an OAuth client at the provider (links in the admin: *Login methods → Configure*).
2. Register the redirect URI shown there, e.g. `https://example.com/login/oauth/google/`.
3. Enter client ID and secret – or, better for the secret, define it in `wp-config.php`:

```php
define( 'PORTIER_GOOGLE_CLIENT_SECRET', '…' );   // also PORTIER_GOOGLE_CLIENT_ID, PORTIER_LINKEDIN_…, PORTIER_DISCORD_…
```

Failed social logins fire `portier_social_login_failed` (reason, provider, technical details) for
logging; visitors only see a plain-language message.

## Emergency access

Locked out? Add this to `wp-config.php` – Portier switches itself off and the default login
returns:

```php
define( 'PORTIER_DISABLE', true );
```

## Development

```bash
composer install
npm install
npm run fonts        # copies the self-hosted Geist font into assets/fonts
npm run build        # or: npm start (watch mode)
```

### Tests & quality

| Command | What |
| --- | --- |
| `composer test` | PHPUnit: unit + integration (WordPress test suite) |
| `composer test:multisite` | integration suite on a multisite network |
| `composer lint` / `composer analyse` | PHPCS (PSR-12 + WordPress security/i18n) / PHPStan level 8 |
| `npm run test:js` | Vitest (frontend script, admin validation) |
| `npm run lint:js` / `npm run lint:css` | ESLint / Stylelint (WordPress config) |
| `npm run test:e2e` | Playwright + axe-core against a running site (desktop + mobile) |

Integration tests need a **throw-away** MySQL database (default `portier_tests` on
`127.0.0.1`, user `root`). Copy `wp-tests-config-sample.php` to `wp-tests-config.php` to change
it. End-to-end tests default to `http://localhost/wp-playground`; see
`tests/e2e/support/site.mjs` for the environment variables.

### Translations

Translations live in `languages/src/*.json` (one file per language, formal variants as
overrides). After changing strings run:

```bash
npm run build && composer i18n
```

## Architecture

```
portier.php              bootstrap, PSR-4 autoloader, requirements check
src/Plugin.php           composition root – the only place that wires hooks
src/Routing/             login URL, rewriting core links, guarding wp-login.php
src/Frontend/            controller (Request → Response), views, assets, language
src/Auth/                login flow, password reset, registration, magic link, pending logins
src/TwoFactor/           TOTP, Base32, recovery codes, per-user store, gate for core logins
src/OAuth/               providers, OAuth/OIDC client, JWT/JWK, state store, account linking
src/Passkeys/            WebAuthn relying party, CBOR, COSE keys, challenges, credentials, REST
src/Security/            rate limiter, login throttle, CSRF token, encryption, client IP, headers
src/Audit/               audit log file: subscriber, settings diff, file with rotation
src/Admin/               settings page, loading placeholders, profile sections (2FA, accounts, passkeys)
src/Settings/            typed settings + REST endpoint for the admin app
templates/               escaped PHP templates (layout, views, partials)
assets/src/              frontend enhancement script + CSS, admin React app
```

The controller never echoes or exits: it returns a `Response` value object, which makes every
screen testable without a browser.

## Roadmap

1. ✅ Core: login address, login, reset, registration, rate limiting, admin, i18n
2. ✅ TOTP 2FA (+ recovery codes, trusted devices), magic link
3. ✅ Google, LinkedIn, Discord (OIDC/OAuth2 with PKCE)
4. ✅ Eight templates, live preview, colours/CSS, contrast check, background image
5. ✅ Passkeys (WebAuthn), sortable methods, "Test connection" – released as 1.0.0

## License

GPL-2.0-or-later. Fonts (Geist, Plus Jakarta Sans, Space Grotesk, Manrope, Bricolage Grotesque,
IBM Plex Sans, Instrument Serif, Archivo Black): SIL Open Font License 1.1 – licence files in
`assets/fonts/`.
Icons: Lucide (ISC).
