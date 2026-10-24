=== Portier ===
Contributors: peterstuhlmann
Tags: login, custom login, passkeys, two-factor authentication, social login
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A modern, accessible login for WordPress: own login address, passkeys, two-factor authentication, social login and eight beautiful templates.

== Description ==

Portier replaces the WordPress login screen with a fast, accessible and secure one – and keeps working with the plugins that hook into the core login (captchas, SSO, security plugins).

= Log in the way people like =

* **Passkeys** (WebAuthn): log in with fingerprint, face or device PIN – no password, no code. Passkey autofill right in the e-mail field. Passkeys only work on your site, so phishing pages cannot use them, and they replace password and second factor in one step.
* **E-mail and password**, on one screen or in two steps (e-mail first, then the password) – without revealing which accounts exist.
* **Magic link**: a one-time login link by e-mail, opened on a confirmation page so e-mail scanners cannot log anybody in.
* **Social login** with Google, LinkedIn and Discord (OpenID Connect / OAuth 2.0 with PKCE). More providers can be added with a filter.
* The order of the buttons below the form is up to you (drag and drop or arrow keys), and "Test connection" checks a provider's credentials before you switch it on.

= Secure by default =

* Own login address (`/login/` by default); `wp-login.php` redirects or answers with 404.
* Brute-force protection on every login path (this page, `wp-login.php`, XML-RPC, other plugins' login forms): per IP with growing lock times plus a per-account limit. Parallel attempts all count.
* Two-factor authentication (TOTP) – off, optional, or required for chosen roles – with recovery codes and "trust this device". Enforced on every password login path. Wrong codes are limited per account, however often someone logs in again; the owner gets an e-mail, because whoever got that far knows the password.
* New ways into an account – adding a passkey, connecting a social account, setting up 2FA, new recovery codes – need a fresh login, so a stolen session cannot create one. The owner gets an e-mail for every new passkey and connected account.
* CSRF protection for logged-out forms, no account enumeration, single-use and browser-bound challenges for passkeys and social login, security headers.
* Secrets (2FA, client secrets) encrypted at rest; only public keys are stored for passkeys.
* An emergency switch: `define( 'PORTIER_DISABLE', true );` in `wp-config.php` brings back the default login.
* An audit log file (no UI): every setting with old and new value, account security changes (2FA, passkeys, connected accounts), logins with their method, failed logins and pauses – with date and time to the second, name, e-mail, IP address and browser.

= Beautiful and accessible =

* Eight templates (Aurora, Pure, Studio, Midnight, Journal, Bold, Cloud, Enterprise) with self-hosted fonts – no font CDN.
* Own colours, corner radius, font, custom CSS and a background image with adjustable overlay, all with a live preview on phone, tablet and desktop and a live WCAG contrast check.
* WCAG 2.2 AA: visible labels, error summaries, focus management, 24 px targets, reduced motion, high-contrast mode, dark mode – checked with axe in automated tests.
* Mobile first, with smooth in-place screen changes (View Transitions); every screen also works without JavaScript.
* Translation ready; German (informal and formal) included.

= Works with your setup =

* Multisite, plain or pretty permalinks, language plugins (Polylang/WPML). On a network, only network administrators can change the security settings – accounts belong to the whole network.
* Core login hooks keep running, so login add-ons keep working.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/portier` or install it from the plugin directory.
2. Activate it. The new login is at `/login/` right away.
3. Open **Portier** in the admin menu to choose the login methods, security settings and design.
4. Passkeys need HTTPS. People add their passkeys in their profile (**Users → Profile → Passkeys**).
5. For social login, create an app at the provider, copy the redirect URI shown in Portier, enter client ID and secret and click **Test connection**.

== Frequently Asked Questions ==

= I locked myself out. How do I get back in? =

Add `define( 'PORTIER_DISABLE', true );` to `wp-config.php`. Portier switches off and the default WordPress login works again. Remove the line to turn Portier back on.

= Do passkeys replace two-factor authentication? =

Yes. A passkey proves possession of the device and checks the person (PIN, fingerprint or face), so it counts as both factors. Passwords still need the second factor when 2FA is on.

= Why is the passkey button missing? =

Passkeys need a secure connection (HTTPS; `http://localhost` works for development) and a browser that supports them. The button only appears when both are given. They can also be switched off under **Portier → Login methods**.

= Can I keep the client secrets out of the database? =

Yes. Define them in `wp-config.php`, e.g. `PORTIER_GOOGLE_CLIENT_ID` and `PORTIER_GOOGLE_CLIENT_SECRET`. The admin then shows them as set there.

= Where is the audit log? =

In `wp-content/portier-logs/`, one file per month (`portier-2026-10-<random>.log`), kept for 12 months. The folder is closed to browsers; the random part keeps the files hidden on servers that ignore `.htaccess` (nginx). Put it elsewhere – best outside the web root – with `define( 'PORTIER_LOG_DIR', '/path/outside/the/web/root' );` in `wp-config.php`. The retention can be changed with the `portier_audit_log_retention_months` filter.

= My site runs behind a proxy or CDN. Which client IP setting is right? =

Only change it if the proxy sets the header – otherwise anybody can forge it. With **X-Forwarded-For**, Portier uses the last public address of the chain: the one your proxy added, not what the visitor sent. **CF-Connecting-IP** is only safe if the server cannot be reached around Cloudflare.

= What if the WordPress salts or PORTIER_ENCRYPTION_KEY change? =

Stored 2FA secrets can no longer be decrypted. Portier then keeps asking for the second factor – it never switches 2FA off by itself – and asks the person to have an administrator reset it (**Users → Edit user**). Passkeys keep working. If no administrator can log in any more: set `PORTIER_DISABLE` (see above), log in, remove the constant again and – still logged in – turn 2FA off in your profile or reset it for others.

= Does Portier work with security or captcha plugins? =

Portier runs the core login hooks (`login_form`, `authenticate`, `wp_login`, `login_enqueue_scripts`, …), so most plugins keep working on the new login page.

== Privacy ==

Portier stores only what the login needs:

* **Login attempt counters** to stop brute-force attacks: short-lived, keyed by a hash of IP address and login name (no readable personal data).
* **Two-factor authentication**: the encrypted secret, hashed recovery codes and trusted-device tokens (per user).
* **Passkeys**: the public key, a name, dates and a random user handle (per user). Private keys never leave the device.
* **Social login**: the provider's user ID and e-mail address of a connected account (per user).
* **Audit log** (a file on the server, 12 months): date and time, name, e-mail address and user ID of the person acting, IP address and browser, and what was done – setting changes, account security changes, logins, failed logins (with the login name entered, only if it belongs to an account), logouts, password resets and new accounts. Secrets and custom CSS are never written.
* **Cookies** (all HttpOnly, only set when needed): `portier_csrf` (form protection), `portier_login` (two-step login), `portier_pending` (waiting second factor), `portier_trusted_device` (optional, "trust this device"), `portier_magic` (magic link), `portier_oauth` (social login), `portier_webauthn` (passkey challenge) and WordPress' own `wp_lang` (chosen language).

Deleting the plugin removes its settings, all of this per-user data and the audit log files.

== External services ==

Portier contacts external services only for the social login providers that an administrator sets up:

* **Google** (accounts.google.com, oauth2.googleapis.com, www.googleapis.com) – when someone logs in with Google or an administrator tests the connection. [Terms](https://policies.google.com/terms), [Privacy](https://policies.google.com/privacy)
* **LinkedIn** (www.linkedin.com, api.linkedin.com) – when someone logs in with LinkedIn or the connection is tested. [User Agreement](https://www.linkedin.com/legal/user-agreement), [Privacy](https://www.linkedin.com/legal/privacy-policy)
* **Discord** (discord.com) – when someone logs in with Discord or the connection is tested. [Terms](https://discord.com/terms), [Privacy](https://discord.com/privacy)

During a login the visitor's browser is sent to the provider, which returns an authorization code; Portier exchanges it for the person's ID and e-mail address. Nothing is sent before someone chooses the provider. Passkeys, fonts and all other features work without external services.

== Screenshots ==

1. The Aurora template on a phone: e-mail first, passkey and social login below.
2. Logging in with a passkey.
3. Login methods in the admin: sortable passkey and provider cards with a preview.
4. The design editor with live preview and contrast check.
5. Passkeys in the user profile.

== Changelog ==

= 1.0.0 =
* Audit log file: settings (old → new), account security, logins and failures with time, person, IP address and browser. Can be switched off.
* CSS editor with syntax highlighting, validation and formatting.
* Passkeys (WebAuthn): add them in the profile, log in with the button, from the second-factor screen or by autofill in the e-mail field. Replace password and second factor.
* Sortable login methods below the form (drag and drop or arrow keys) with a preview.
* "Test connection" for social login providers.
* Smooth view transitions between screens, a language choice that sticks, precise loading placeholders in the admin.
* First public release: own login address, two-factor authentication, magic link, social login, eight templates with design editor.

== Upgrade Notice ==

= 1.0.0 =
First public release.
