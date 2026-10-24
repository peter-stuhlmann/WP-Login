<?php

declare(strict_types=1);

namespace Portier;

use Portier\Auth\LoginFlow;
use Portier\Auth\LoginService;
use Portier\Auth\MagicLinkService;
use Portier\Auth\PasswordResetService;
use Portier\Auth\PendingLogin;
use Portier\Auth\RecentLogin;
use Portier\Auth\RegistrationService;
use Portier\Frontend\Assets;
use Portier\Frontend\Controller;
use Portier\Frontend\Language;
use Portier\Frontend\MagicLinkScreens;
use Portier\Frontend\PasskeyScreens;
use Portier\Frontend\Responder;
use Portier\Frontend\SocialScreens;
use Portier\Frontend\TwoFactorScreens;
use Portier\Frontend\View;
use Portier\Http\AfterResponse;
use Portier\Http\Request;
use Portier\Http\Response;
use Portier\OAuth\OAuthClient;
use Portier\OAuth\SocialAccounts;
use Portier\OAuth\StateStore;
use Portier\Passkeys\ChallengeStore;
use Portier\Passkeys\CredentialStore;
use Portier\Passkeys\PasskeyRestController;
use Portier\Passkeys\PasskeyService;
use Portier\Passkeys\RelyingParty;
use Portier\Routing\CoreLoginGuard;
use Portier\Routing\LoginRoute;
use Portier\Routing\UrlRewriter;
use Portier\Security\ClientIp;
use Portier\Security\Crypto;
use Portier\Security\FormToken;
use Portier\Security\LoginThrottle;
use Portier\Security\RateLimiter;
use Portier\Security\TransientAttemptStore;
use Portier\Settings\RestController;
use Portier\Settings\Settings;
use Portier\TwoFactor\TwoFactorGate;
use Portier\TwoFactor\TwoFactorService;
use Portier\TwoFactor\UserStore;
use WP;

/**
 * Composition root: creates the services and connects them to WordPress hooks.
 * No other class registers hooks on its own initiative.
 */
final class Plugin
{
    /** Nonce action of the design editor's live preview. */
    public const PREVIEW_NONCE = 'portier-preview';

    private static ?self $instance = null;

    private bool $booted = false;
    private Settings $settings;
    private ?LoginRoute $route = null;
    private ?TwoFactorService $twoFactor = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Kill switch: `define('PORTIER_DISABLE', true);` in wp-config.php turns every Portier
     * feature off and restores the core login – the documented way back in if you lock yourself out.
     */
    public static function killSwitchActive(): bool
    {
        return defined('PORTIER_DISABLE') && PORTIER_DISABLE;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;
        $this->settings = new Settings();
        // Any change of the option (REST, WP-CLI, other code) is picked up immediately.
        add_action('add_option_' . Settings::OPTION, [$this->settings, 'flushCache']);
        add_action('update_option_' . Settings::OPTION, [$this->settings, 'flushCache']);

        add_action('init', [$this, 'loadTextdomain'], 0);
        // Audit log (file, no UI): every setting and security event – also while switched off.
        (new Audit\AuditSubscriber())->register();
        (new RestController($this->settings))->register();
        (new Admin\AdminPage())->register();

        if (self::killSwitchActive()) {
            // Remove our rewrite rule (if any) and do nothing else.
            (new LoginRoute($this->settings->string('slug'), false))->register();
            return;
        }

        $this->throttle()->register();
        if ($this->settings->bool('disable_xmlrpc')) {
            add_filter('xmlrpc_enabled', '__return_false');
        }

        $enabled = $this->settings->bool('enabled');
        $this->route = new LoginRoute($this->settings->string('slug'), $enabled);
        $this->route->register();
        if (! $enabled) {
            return;
        }

        $rewriter = new UrlRewriter($this->route);
        $rewriter->register();
        // 2FA is enforced only while Portier's screens exist – otherwise nobody could log in.
        (new TwoFactorGate($this->twoFactor(), $this->route))->register();
        (new Admin\ProfileSection($this->twoFactor(), $this->route))->register();
        (new Admin\SocialProfileSection($this->socialAccounts(), $this->route, $this->settings))->register();
        // Passkeys: managed in the profile, used on Portier's login screen.
        $passkeyRest = new PasskeyRestController($this->passkeys(), $this->recentLogin());
        $passkeyRest->register();
        (new Admin\PasskeyProfileSection($this->passkeys(), $passkeyRest))->register();
        (new CoreLoginGuard($rewriter, $this->settings->string('core_login_behavior')))->register();
        (new Assets($this->settings))->register();
        add_action('parse_request', [$this, 'handleRequest'], 1);
    }

    public function loadTextdomain(): void
    {
        load_plugin_textdomain('portier', false, dirname(plugin_basename(PORTIER_FILE)) . '/languages');
    }

    /**
     * Serves the login page as early as possible (no main query, no theme).
     */
    public function handleRequest(WP $wp): void
    {
        if ($this->route === null || ! $this->route->matches($wp)) {
            return;
        }

        $request = Request::fromGlobals();
        $provider = $wp->query_vars[LoginRoute::PROVIDER_VAR] ?? '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Parsed and sanitized in callbackRequest().
        $request = SocialScreens::callbackRequest($request, is_string($provider) ? $provider : '', (string) ($_SERVER['QUERY_STRING'] ?? ''));
        $locale = Language::fromRequest($request);
        if ($locale !== null) {
            // Remember every explicit choice – also the site's default language: otherwise an
            // older choice in the cookie would win again on the next screen or reload.
            if ($request->query('wp_lang') === $locale) {
                // Same cookie as wp-login.php, so the choice also applies to core screens.
                $options = ['expires' => 0, 'path' => COOKIEPATH ?: '/', 'domain' => COOKIE_DOMAIN ?: '', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax'];
                setcookie('wp_lang', $locale, $options);
            }
            if ($locale !== determine_locale()) {
                switch_to_locale($locale);
            }
        }

        // Behave like wp-login.php for plugins checking the context.
        add_filter('wp_robots', 'wp_robots_sensitive_page');
        add_action('login_head', 'wp_strict_cross_origin_referrer');

        if ($request->query('portier_preview') !== '') {
            $this->preview($request)->send();
            exit;
        }
        $this->controller($request)->handle($request)->send();
        AfterResponse::finish();
        exit;
    }

    /**
     * Live preview of the design editor: only for administrators with a valid nonce. Unsaved
     * design values from the query apply to this request only.
     */
    public function preview(Request $request): Response
    {
        if (! current_user_can('manage_options') || ! wp_verify_nonce($request->query('portier_preview'), self::PREVIEW_NONCE)) {
            return Response::html(esc_html__('This preview has expired. Please reload the settings page.', 'portier'), 403);
        }
        $draft = $request->query['preview'] ?? [];
        $this->settings->preview(is_array($draft) ? $draft : []);
        return $this->controller($request)->preview($request);
    }

    public function controller(Request $request): Controller
    {
        $ip = ClientIp::resolve($request->server, $this->settings->string('trusted_proxy_header'));
        [$mailLimiter, $perAccountMail] = $this->mailLimiters();
        $route = $this->loginRoute();

        $view = new View(PORTIER_DIR . '/templates');
        $responder = new Responder($route, $this->settings, $view, new FormToken(wp_salt('nonce'), $request->cookie(FormToken::COOKIE)));
        $flow = new LoginFlow($this->twoFactor(), new PendingLogin());
        $passkeyScreens = new PasskeyScreens(
            $responder,
            $this->passkeys(),
            $flow,
            // Options and failed checks per IP: 60 in 15 minutes (each option set is a stored challenge).
            new RateLimiter(new TransientAttemptStore(), 60, 15 * MINUTE_IN_SECONDS, 15 * MINUTE_IN_SECONDS, HOUR_IN_SECONDS),
            $ip
        );

        return new Controller(
            $responder,
            new LoginService($flow),
            new PasswordResetService($mailLimiter, $perAccountMail, $ip, $this->settings->int('min_password_length')),
            new RegistrationService($mailLimiter, $ip),
            new TwoFactorScreens($responder, $flow, $this->recentLogin(), $passkeyScreens->offerOnSecondFactor(...)),
            new MagicLinkScreens($responder, $this->magicLinks($ip), $flow),
            new SocialScreens(
                $responder,
                new OAuthClient(),
                new StateStore(),
                $this->socialAccounts(),
                $flow,
                Crypto::fromWordPress(),
                // Starting a social login stores a small record: 30 starts per IP and 15 minutes.
                new RateLimiter(new TransientAttemptStore(), 30, 15 * MINUTE_IN_SECONDS, 15 * MINUTE_IN_SECONDS, HOUR_IN_SECONDS),
                $ip,
                $this->recentLogin()
            ),
            $passkeyScreens,
        );
    }

    /**
     * Passkeys (WebAuthn) for this site.
     */
    public function passkeys(): PasskeyService
    {
        return new PasskeyService(RelyingParty::forSite(), new ChallengeStore(), new CredentialStore(), $this->settings);
    }

    /**
     * Links between provider identities and WordPress accounts.
     */
    public function socialAccounts(): SocialAccounts
    {
        return new SocialAccounts($this->settings->stringList('social_blocked_roles'));
    }

    /**
     * Magic link service configured from the settings, rate limited for the given client IP.
     */
    public function magicLinks(string $ip): MagicLinkService
    {
        [$mailLimiter, $perAccountMail] = $this->mailLimiters();
        return new MagicLinkService(
            $this->loginRoute(),
            $mailLimiter,
            $perAccountMail,
            $ip,
            $this->settings->int('magic_link_minutes'),
            Crypto::fromWordPress()->hashKey('magic-link')
        );
    }

    /**
     * Limits for outgoing e-mails (reset, registration, magic link): a handful per IP and
     * 15 minutes, and a few per account and hour.
     *
     * @return array{0: RateLimiter, 1: RateLimiter}
     */
    private function mailLimiters(): array
    {
        $store = new TransientAttemptStore();
        return [
            new RateLimiter($store, 5, 15 * MINUTE_IN_SECONDS, 15 * MINUTE_IN_SECONDS, DAY_IN_SECONDS),
            new RateLimiter($store, 3, HOUR_IN_SECONDS, HOUR_IN_SECONDS, DAY_IN_SECONDS),
        ];
    }

    private function loginRoute(): LoginRoute
    {
        return $this->route ?? new LoginRoute($this->settings->string('slug'), true);
    }

    /**
     * Two-factor service configured from the settings (shared by login, gate and profile).
     */
    public function twoFactor(): TwoFactorService
    {
        return $this->twoFactor ??= new TwoFactorService(
            new UserStore(Crypto::fromWordPress()),
            $this->settings,
            // Wrong codes per account, wherever they come from: 10 per hour, then a pause of 15
            // minutes that doubles up to a day – about 70 guesses a day out of a million codes.
            new RateLimiter(new TransientAttemptStore(), 10, HOUR_IN_SECONDS, 15 * MINUTE_IN_SECONDS, DAY_IN_SECONDS)
        );
    }

    /**
     * "Confirm it's you" before changes that add a way into an account.
     */
    public function recentLogin(): RecentLogin
    {
        return new RecentLogin($this->loginRoute());
    }

    public function settings(): Settings
    {
        return $this->settings;
    }

    private function throttle(): LoginThrottle
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated as IP inside.
        $ip = ClientIp::resolve($_SERVER, $this->settings->string('trusted_proxy_header'));
        $store = new TransientAttemptStore();
        $lockout = $this->settings->int('rate_limit_lockout_minutes') * MINUTE_IN_SECONDS;

        return new LoginThrottle(
            new RateLimiter($store, $this->settings->int('rate_limit_attempts'), 15 * MINUTE_IN_SECONDS, $lockout, DAY_IN_SECONDS),
            new RateLimiter($store, $this->settings->int('rate_limit_account_attempts'), HOUR_IN_SECONDS, 15 * MINUTE_IN_SECONDS, HOUR_IN_SECONDS),
            $ip
        );
    }
}
