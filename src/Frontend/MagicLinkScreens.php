<?php

declare(strict_types=1);

namespace Portier\Frontend;

use Portier\Auth\AuthResult;
use Portier\Auth\LoginFlow;
use Portier\Auth\MagicLinkService;
use Portier\Auth\RedirectResolver;
use Portier\Http\Request;
use Portier\Http\Response;
use Portier\TwoFactor\TwoFactorService;
use WP_Error;
use WP_User;

/**
 * Magic link screens: request a link, then confirm the login on the page the link opens.
 */
final class MagicLinkScreens
{
    /** Holds the token after it was moved out of the URL. */
    public const COOKIE = 'portier_magic';

    public function __construct(
        private readonly Responder $responder,
        private readonly MagicLinkService $magicLinks,
        private readonly LoginFlow $flow,
    ) {
    }

    public function request(Request $request): Response
    {
        if (! $this->responder->settings()->bool('magic_link')) {
            return $this->responder->redirect($request, $this->responder->route()->url());
        }
        $redirectTo = RedirectResolver::sanitizeRequested($request->isPost() ? $request->input('redirect_to') : $request->query('redirect_to'));

        $result = null;
        if ($request->isPost()) {
            $tokenError = $this->responder->verifyToken($request, 'magiclink');
            $result = $tokenError !== null
                ? AuthResult::formError($tokenError, ['login' => $request->input('user_login')])
                : $this->magicLinks->request($request->input('user_login'), $redirectTo);
            if ($result->success) {
                return $this->responder->redirect($request, $this->responder->route()->url('checkemail', ['checkemail' => 'magic']));
            }
        }

        return $this->responder->page($request, 'magiclink', 'magic-request', __('Log in with a link', 'portier'), [
            'errors' => $result->errors ?? [],
            'values' => ['user_login' => (string) ($result?->data['login'] ?? sanitize_text_field($request->query('user_login')))],
            'redirect_to' => $redirectTo,
        ], $result === null ? 200 : 422);
    }

    public function confirm(Request $request): Response
    {
        // The link from the e-mail: move the token into a cookie, drop it from the URL.
        if (! $request->isPost() && $request->query('token') !== '') {
            return $this->responder->withCookie(
                Response::redirect($this->responder->route()->url('magic'), 302),
                self::COOKIE,
                $request->query('token'),
                time() + HOUR_IN_SECONDS
            );
        }

        // Switching login links off also stops the links that were already sent.
        if (! $this->responder->settings()->bool('magic_link')) {
            return $this->invalid($request, false);
        }
        $token = (string) $request->cookie(self::COOKIE);
        $user = $this->magicLinks->check($token);
        if ($user instanceof WP_Error) {
            return $this->invalid($request, $user->get_error_code() === 'expired_magic_link');
        }

        if ($request->isPost()) {
            $tokenError = $this->responder->verifyToken($request, 'magic');
            if ($tokenError !== null) {
                return $this->confirmPage($request, $user, [AuthResult::FORM => $tokenError]);
            }
            $consumed = $this->magicLinks->consume($token);
            if ($consumed instanceof WP_Error) {
                return $this->invalid($request, false);
            }
            $result = $this->flow->afterFirstFactor(
                $consumed['user'],
                $request->input('rememberme') !== '',
                RedirectResolver::sanitizeRequested($consumed['redirect']),
                (string) $request->cookie(TwoFactorService::DEVICE_COOKIE),
                'magic_link'
            );
            return $this->responder->withoutCookie($this->responder->afterAuth($request, $result), self::COOKIE);
        }

        return $this->confirmPage($request, $user);
    }

    /**
     * @param array<string, string> $errors
     */
    private function confirmPage(Request $request, WP_User $user, array $errors = []): Response
    {
        return $this->responder->page($request, 'magic', 'magic-confirm', __('Confirm your login', 'portier'), [
            'errors' => $errors,
            'user' => $user,
            'device' => Device::describe((string) ($request->server['HTTP_USER_AGENT'] ?? '')),
        ], $errors === [] ? 200 : 422);
    }

    private function invalid(Request $request, bool $expired): Response
    {
        return $this->responder->withoutCookie(
            $this->responder->page($request, 'magic', 'magic-invalid', __('This login link is no longer valid', 'portier'), ['expired' => $expired], 410),
            self::COOKIE
        );
    }
}
