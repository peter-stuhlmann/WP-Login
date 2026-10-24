<?php

declare(strict_types=1);

namespace Portier\Frontend;

use Portier\Auth\AuthResult;
use Portier\Auth\LoginService;
use Portier\Auth\PasswordResetService;
use Portier\Auth\RedirectResolver;
use Portier\Auth\RegistrationService;
use Portier\Http\Request;
use Portier\Http\Response;
use Portier\OAuth\OAuthException;
use Portier\OAuth\Providers;
use Portier\Routing\CoreLogin;
use Portier\Routing\LoginRoute;
use Portier\TwoFactor\TwoFactorService;
use WP_User;

/**
 * Entry point for every request to the Portier login address: routes the action to its
 * screen and handles the account screens (login, lost password, reset, registration).
 *
 * Each screen works as a classic HTML form (Post/Redirect/Get) without JavaScript. The
 * enhancement script sends the same requests with `X-Portier-Request: fragment` and receives
 * JSON containing only the card's HTML, which it swaps in with a view transition.
 */
final class Controller
{
    /** @deprecated Kept for backwards compatibility – use {@see Responder::HONEYPOT_FIELD}. */
    public const HONEYPOT_FIELD = Responder::HONEYPOT_FIELD;

    /** Core's cookie name, so a reset started in core can be finished here and vice versa. */
    private const RESET_COOKIE_PREFIX = 'wp-resetpass-';

    /** Two-step login: the login entered in step 1 (session cookie on the login path). */
    public const LOGIN_COOKIE = 'portier_login';

    public function __construct(
        private readonly Responder $responder,
        private readonly LoginService $loginService,
        private readonly PasswordResetService $resetService,
        private readonly RegistrationService $registrationService,
        private readonly TwoFactorScreens $twoFactorScreens,
        private readonly MagicLinkScreens $magicLinkScreens,
        private readonly SocialScreens $socialScreens,
        private readonly PasskeyScreens $passkeyScreens,
    ) {
    }

    public function handle(Request $request): Response
    {
        // Audit log entries of this request come from the login page.
        \Portier\Audit\AuditLog::$onLoginPage = true;
        // Return from a social login provider (/login/oauth/<provider>/).
        $callback = $request->query(LoginRoute::PROVIDER_VAR);
        if ($callback !== '') {
            return $this->responder->finalize($this->socialScreens->callback($request->withQuery(['provider' => $callback])));
        }

        $action = CoreLogin::portierAction($request->query);
        if ($action === null) {
            // Interim login & pass-through actions are core business.
            return Response::redirect(CoreLogin::url(array_filter($request->query, 'is_scalar')), 302);
        }

        $response = match ($action) {
            'lostpassword' => $this->lostPassword($request),
            'rp' => $this->resetPassword($request),
            'register' => $this->register($request),
            'checkemail' => $this->checkEmail($request),
            '2fa' => $this->twoFactorScreens->verify($request),
            'recovery' => $this->twoFactorScreens->recovery($request),
            '2fa-setup' => $this->twoFactorScreens->setup($request),
            'recovery-codes' => $this->twoFactorScreens->codes($request),
            'magiclink' => $this->magicLinkScreens->request($request),
            'magic' => $this->magicLinkScreens->confirm($request),
            'oauth' => $this->socialScreens->start($request),
            'passkey' => $this->passkeyScreens->handle($request),
            default => $this->login($request),
        };

        return $this->responder->finalize($response);
    }

    /**
     * Live preview for the design editor (admins only, checked by the caller): renders a screen
     * with example data – independent of who is logged in – and may be framed by the admin.
     */
    public function preview(Request $request): Response
    {
        $example = __('name@example.com', 'portier');
        $response = match ($request->query('state')) {
            'password' => $this->passwordPage($request, '', $example),
            'error' => $this->loginPage($request, '', AuthResult::formError(
                __('The email address/username or password is incorrect. Please check your input and try again.', 'portier'),
                ['login' => $example]
            ), 422),
            '2fa' => $this->responder->page($request, '2fa', 'two-factor', __('Two-factor authentication', 'portier'), ['trust_days' => 30]),
            'lostpassword' => $this->responder->page($request, 'lostpassword', 'lost-password', __('Forgot your password?', 'portier'), []),
            'register' => $this->registerPage($request, AuthResult::success(), $this->consentRequired()),
            default => $this->loginPage($request, ''),
        };
        return $this->responder->finalize($response, true);
    }

    // ------------------------------------------------------------------ Screens

    /**
     * Log in – on one screen, or in two steps (e-mail first, then the password; setting
     * "login_steps"). Step 1 never checks whether the account exists: step 2 looks the same for
     * everybody, so nobody can find out which addresses are registered.
     */
    private function login(Request $request): Response
    {
        $redirectTo = RedirectResolver::sanitizeRequested($request->isPost() ? $request->input('redirect_to') : $request->query('redirect_to'));
        $twoSteps = $this->responder->settings()->string('login_steps') === 'two';

        if ($request->isPost()) {
            // Step 1 posts only the login; everything else (step 2, one-step form, other
            // plugins' forms) posts login and password together.
            $identify = $twoSteps && $request->input('portier_step') === 'identify';
            $tokenError = $this->responder->verifyToken($request, 'login');
            if ($tokenError !== null) {
                $result = AuthResult::formError($tokenError, ['login' => $request->input('log')]);
                return $identify || ! $twoSteps
                    ? $this->loginPage($request, $redirectTo, $result, 422)
                    : $this->passwordPage($request, $redirectTo, $request->input('log'), $result);
            }
            if ($identify) {
                return $this->identify($request, $redirectTo);
            }
            $result = $this->loginService->attempt(
                $request->input('log'),
                $request->input('pwd'),
                $request->input('rememberme') !== '',
                $redirectTo,
                (string) $request->cookie(TwoFactorService::DEVICE_COOKIE)
            );
            if ($result->success) {
                return $this->responder->withoutCookie($this->responder->afterAuth($request, $result), self::LOGIN_COOKIE);
            }
            return $twoSteps && $request->input('portier_step') === 'password'
                ? $this->passwordPage($request, $redirectTo, $request->input('log'), $result)
                : $this->loginPage($request, $redirectTo, $result, 422);
        }

        if ($request->query('reauth') === '1') {
            wp_clear_auth_cookie();
        } elseif (is_user_logged_in()) {
            return $this->loggedInPage($request, $redirectTo);
        }
        if ($twoSteps && $request->query('step') === 'password') {
            $login = (string) $request->cookie(self::LOGIN_COOKIE);
            if ($login === '') {
                // Cookie expired or step 2 opened directly: start again with step 1.
                return $this->responder->redirect($request, $this->responder->route()->url('login', array_filter(['redirect_to' => $redirectTo])));
            }
            return $this->passwordPage($request, $redirectTo, $login);
        }
        return $this->loginPage($request, $redirectTo);
    }

    /**
     * Two-step login, step 1 done: remember the login (HttpOnly cookie, never in the URL) and
     * continue with the password.
     */
    private function identify(Request $request, string $redirectTo): Response
    {
        $login = mb_substr(trim($request->input('log')), 0, 200);
        if ($login === '') {
            return $this->loginPage($request, $redirectTo, AuthResult::failure(['log' => __('Please enter your email address or username.', 'portier')]), 422);
        }
        return $this->responder->withCookie(
            $this->responder->redirect($request, $this->responder->route()->url('login', array_filter(['step' => 'password', 'redirect_to' => $redirectTo]))),
            self::LOGIN_COOKIE,
            $login
        );
    }

    private function lostPassword(Request $request): Response
    {
        $title = __('Forgot your password?', 'portier');
        if ($request->isPost()) {
            $tokenError = $this->responder->verifyToken($request, 'lostpassword');
            $result = $tokenError !== null
                ? AuthResult::formError($tokenError, ['login' => $request->input('user_login')])
                : $this->resetService->request($request->input('user_login'));
            if ($result->success) {
                return $this->responder->redirect($request, $this->responder->route()->url('checkemail', ['checkemail' => 'confirm']));
            }
            return $this->responder->page($request, 'lostpassword', 'lost-password', $title, [
                'errors' => $result->errors,
                'values' => ['user_login' => (string) ($result->data['login'] ?? '')],
            ], 422);
        }

        $notice = null;
        if (in_array($request->query('error'), ['expiredkey', 'invalidkey'], true)) {
            $notice = ['type' => 'error', 'text' => __('This password reset link is no longer valid. Please request a new one.', 'portier')];
        }
        return $this->responder->page($request, 'lostpassword', 'lost-password', $title, [
            'notice' => $notice,
            'values' => ['user_login' => sanitize_text_field($request->query('user_login'))],
        ]);
    }

    private function checkEmail(Request $request): Response
    {
        $variant = $request->query('checkemail');
        $variant = in_array($variant, ['registered', 'magic'], true) ? $variant : 'confirm';
        return $this->responder->page($request, 'checkemail', 'check-email', __('Check your inbox', 'portier'), [
            'variant' => $variant,
            'minutes' => $this->responder->settings()->int('magic_link_minutes'),
        ]);
    }

    private function resetPassword(Request $request): Response
    {
        $cookieName = self::RESET_COOKIE_PREFIX . COOKIEHASH;

        // Step 1: the link from the e-mail. Move the key into a cookie and drop it from the URL,
        // so it can neither leak via the Referer header nor stay in the browser history.
        if (! $request->isPost() && $request->query('key') !== '' && $request->query('login') !== '') {
            return $this->responder->withCookie(
                Response::redirect($this->responder->route()->url('rp'), 302),
                $cookieName,
                $request->query('login') . ':' . $request->query('key')
            );
        }

        [$login, $key] = array_pad(explode(':', (string) $request->cookie($cookieName), 2), 2, '');
        $user = $this->resetService->checkKey($key, $login);
        if (! $user instanceof WP_User) {
            return $this->responder->withoutCookie(
                $this->responder->page($request, 'rp', 'reset-invalid', __('This link is no longer valid', 'portier'), [], 410),
                $cookieName
            );
        }

        $result = null;
        if ($request->isPost()) {
            $tokenError = $this->responder->verifyToken($request, 'rp');
            $result = $tokenError !== null
                ? AuthResult::formError($tokenError)
                : $this->resetService->reset($user, $request->input('pass1'), $request->input('pass2'));
            if ($result->success) {
                return $this->responder->withoutCookie(
                    $this->responder->toLogin($request, 'password-changed'),
                    $cookieName
                );
            }
        }

        return $this->responder->page($request, 'rp', 'reset-password', __('Set a new password', 'portier'), [
            'errors' => $result->errors ?? [],
            'user' => $user,
            'min_length' => $this->resetService->minLength(),
        ], $result === null ? 200 : 422);
    }

    private function register(Request $request): Response
    {
        if (! RegistrationService::isOpen()) {
            return $this->responder->page($request, 'register', 'register-closed', __('Registration is closed', 'portier'), [], 403);
        }

        $consentRequired = $this->consentRequired();
        if (! $request->isPost()) {
            return $this->registerPage($request, AuthResult::success(), $consentRequired);
        }

        $tokenError = $this->responder->verifyToken($request, 'register');
        if ($tokenError !== null) {
            return $this->registerPage($request, AuthResult::formError($tokenError, $this->registrationInput($request)), $consentRequired, 422);
        }

        // Bot traps: a filled honeypot, a forged timestamp or an impossibly fast submission
        // (< 2 s). Bots get the normal success answer, so they cannot learn how they were caught.
        $registered = $this->responder->route()->url('checkemail', ['checkemail' => 'registered']);
        $age = $this->responder->token()->timestampAge('register', $request->input('portier_ts'));
        if ($request->input(Responder::HONEYPOT_FIELD) !== '' || $age === null || $age < 2) {
            return $this->responder->redirect($request, $registered);
        }
        if ($age > DAY_IN_SECONDS) {
            return $this->registerPage(
                $request,
                AuthResult::formError(__('The form has expired. Please submit it again.', 'portier'), $this->registrationInput($request)),
                $consentRequired,
                422
            );
        }

        $result = $this->registrationService->register(
            $request->input('user_email'),
            $request->input('display_name'),
            $request->input('privacy_consent') !== '',
            $consentRequired
        );
        if ($result->success) {
            return $this->responder->redirect($request, $registered);
        }
        return $this->registerPage($request, $result, $consentRequired, 422);
    }

    // ------------------------------------------------------------------ Page builders

    /**
     * Login screen: both fields (one step) or step 1 of the two-step login.
     */
    private function loginPage(Request $request, string $redirectTo, ?AuthResult $result = null, int $status = 200): Response
    {
        $twoSteps = $this->responder->settings()->string('login_steps') === 'two';
        // Prefill: the posted value, a carried ?user_login=, or the login from step 1 ("Change").
        $login = (string) ($result?->data['login'] ?? '');
        if ($login === '') {
            $login = $request->isPost() ? $request->input('log') : sanitize_user($request->query('user_login'), false);
        }
        if ($login === '' && $twoSteps) {
            $login = (string) $request->cookie(self::LOGIN_COOKIE);
        }
        return $this->responder->page($request, 'login', $twoSteps ? 'login-identify' : 'login', __('Log in', 'portier'), [
            'errors' => $result->errors ?? [],
            'notice' => $result === null ? $this->loginNotice($request) : null,
            'values' => ['log' => $login],
            'redirect_to' => $redirectTo,
            // After a failed attempt the choice is kept.
            'remember_default' => $request->isPost() && $request->input('rememberme') !== '',
            'methods' => $this->methods($redirectTo),
            'passkey_autofill' => $this->passkeyScreens->enabled(),
        ], $result === null ? 200 : $status);
    }

    /**
     * Methods below the form – passkey button and social providers – in the admin's order.
     *
     * @return list<array<string, string>> Each: type ("passkey" | "social"), id, url and (social) label.
     */
    private function methods(string $redirectTo): array
    {
        $methods = [];
        if ($this->passkeyScreens->enabled()) {
            $methods['passkey'] = [
                'type' => 'passkey',
                'id' => 'passkey',
                'url' => $this->responder->route()->url('passkey', array_filter(['redirect_to' => $redirectTo])),
            ];
        }
        foreach ($this->socialScreens->buttons($redirectTo) as $button) {
            $methods[$button['id']] = ['type' => 'social'] + $button;
        }
        return MethodOrder::apply($methods, $this->responder->settings()->stringList('method_order'));
    }

    /**
     * Two-step login, step 2: the password for the login entered before.
     */
    private function passwordPage(Request $request, string $redirectTo, string $login, ?AuthResult $result = null): Response
    {
        $keep = array_filter(['redirect_to' => $redirectTo]);
        $route = $this->responder->route();
        $errors = $result->errors ?? [];
        // Figma: "Enter your password"; after a failed attempt simply "Log in".
        $title = $errors === [] ? __('Enter your password', 'portier') : __('Log in', 'portier');
        return $this->responder->page($request, 'login', 'login-password', $title, [
            'errors' => $errors,
            'back' => $route->url('login', $keep),
            'login' => $login,
            'redirect_to' => $redirectTo,
            // After a failed attempt the choice is kept.
            'remember_default' => $request->isPost() && $request->input('rememberme') !== '',
            'change_url' => $route->url('login', $keep),
            'magic_url' => $this->responder->settings()->bool('magic_link') ? $route->url('magiclink', ['user_login' => $login] + $keep) : '',
            // The page is about the password: put the cursor there (errors first, if any).
            'focus' => 'portier-pwd',
        ], $result === null ? 200 : 422, ['step' => 'password']);
    }

    private function loggedInPage(Request $request, string $redirectTo): Response
    {
        $user = wp_get_current_user();
        return $this->responder->page($request, 'login', 'logged-in', __('You are already logged in', 'portier'), [
            'user' => $user,
            'continue_url' => RedirectResolver::forUser($redirectTo, $user),
            'logout_url' => wp_logout_url($this->responder->route()->url()),
        ]);
    }

    private function registerPage(Request $request, AuthResult $result, bool $consentRequired, int $status = 200): Response
    {
        return $this->responder->page($request, 'register', 'register', __('Create your account', 'portier'), [
            'errors' => $result->errors,
            'values' => $result->data + $this->registrationInput($request),
            'consent_required' => $consentRequired,
            'privacy_url' => get_privacy_policy_url(),
            'timestamp' => $this->responder->token()->timestamp('register'),
        ], $status);
    }

    /**
     * Status message on the login screen, from core's query arguments or Portier's `notice`.
     *
     * @return array{type: string, text: string}|null
     */
    private function loginNotice(Request $request): ?array
    {
        $notice = $request->query('notice');
        $messages = [
            'password-changed' => ['success', __('Your password has been changed. You can now log in with it.', 'portier')],
            'session-expired' => ['info', __('Your login took too long and has expired for your security. Please log in again.', 'portier')],
            'too-many-codes' => ['error', __('Too many wrong codes. For your security, please log in again.', 'portier')],
        ];
        if (isset($messages[$notice])) {
            return ['type' => $messages[$notice][0], 'text' => $messages[$notice][1]];
        }
        if (str_starts_with($notice, 'oauth-')) {
            return $this->socialNotice(substr($notice, 6), Providers::get($request->query('provider'))->label ?? '');
        }
        if ($request->query('loggedout') === 'true') {
            return ['type' => 'success', 'text' => __('You are now logged out. See you soon!', 'portier')];
        }
        if ($request->query('password') === 'changed') {
            return ['type' => 'success', 'text' => $messages['password-changed'][1]];
        }
        if ($request->query('reauth') === '1') {
            return ['type' => 'info', 'text' => __('Please log in again to continue.', 'portier')];
        }
        if ($request->query('registration') === 'disabled') {
            return ['type' => 'info', 'text' => __('Registration is currently closed.', 'portier')];
        }
        return null;
    }

    /**
     * Message after a failed social login.
     *
     * @return array{type: string, text: string}
     */
    private function socialNotice(string $reason, string $provider): array
    {
        if ($reason === 'limit') {
            return ['type' => 'error', 'text' => __('Too many login attempts. Please wait a few minutes and try again.', 'portier')];
        }
        $provider = $provider !== '' ? $provider : __('this provider', 'portier');
        [$type, $text] = match ($reason) {
            /* translators: %s: provider name, e.g. Google */
            OAuthException::CANCELLED => ['info', __('The login with %s was cancelled. You can choose another way to log in.', 'portier')],
            /* translators: %s: provider name, e.g. Google */
            OAuthException::STATE => ['error', __('The login with %s took too long or was started in another browser. Please try again.', 'portier')],
            /* translators: %s: provider name, e.g. Google */
            OAuthException::PROVIDER => ['error', __('%s could not be reached. Please try again later or log in another way.', 'portier')],
            /* translators: %s: provider name, e.g. Google */
            OAuthException::TOKEN => ['error', __('The answer from %s could not be verified. Please try again.', 'portier')],
            /* translators: %s: provider name, e.g. Google */
            OAuthException::EMAIL => ['error', __('%s did not confirm an email address. Please verify your email address there or log in another way.', 'portier')],
            /* translators: %s: provider name, e.g. Google */
            OAuthException::NO_ACCOUNT => ['info', __('There is no account here for the email address of your %s account yet.', 'portier')],
            OAuthException::LINK_DISABLED => [
                'info',
                /* translators: %s: provider name, e.g. Google */
                __('An account with this email address already exists. Log in with your password and connect %s in your profile afterwards.', 'portier'),
            ],
            /* translators: %s: provider name, e.g. Google */
            OAuthException::LINKED_ELSEWHERE => ['error', __('This %s account is already connected to another account.', 'portier')],
            /* translators: %s: provider name, e.g. Google */
            OAuthException::BLOCKED => ['error', __('For security reasons this account cannot log in with %s. Please log in with your password.', 'portier')],
            /* translators: %s: provider name, e.g. Google */
            default => ['error', __('The login with %s is not available at the moment.', 'portier')],
        };
        return ['type' => $type, 'text' => sprintf($text, $provider)];
    }

    private function consentRequired(): bool
    {
        return $this->responder->settings()->bool('require_privacy_consent') && get_privacy_policy_url() !== '';
    }

    /**
     * @return array{email: string, name: string}
     */
    private function registrationInput(Request $request): array
    {
        return ['email' => trim($request->input('user_email')), 'name' => trim($request->input('display_name'))];
    }
}
