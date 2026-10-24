<?php

declare(strict_types=1);

namespace Portier\Frontend;

use Portier\Auth\LoginFlow;
use Portier\Auth\PendingLogin;
use Portier\Auth\RedirectResolver;
use Portier\Http\Request;
use Portier\Http\Response;
use Portier\Passkeys\ChallengeStore;
use Portier\Passkeys\PasskeyException;
use Portier\Passkeys\PasskeyService;
use Portier\Security\RateLimiter;
use WP_User;

/**
 * Passkey login (Figma "04 · Passkey").
 *
 * GET shows the waiting screen; the script then asks the device. Two POST steps (JSON):
 * `options` issues a challenge bound to this browser, `verify` checks the device's answer and
 * starts the session. A passkey replaces password and second factor; if a login was waiting
 * for its second factor, it is completed for the same account.
 */
final class PasskeyScreens
{
    public function __construct(
        private readonly Responder $responder,
        private readonly PasskeyService $passkeys,
        private readonly LoginFlow $flow,
        private readonly RateLimiter $limiter,
        private readonly string $clientIp,
    ) {
    }

    public function enabled(): bool
    {
        return $this->passkeys->enabled();
    }

    public function handle(Request $request): Response
    {
        if (! $this->passkeys->enabled()) {
            return $request->isPost()
                ? Response::json(['error' => PasskeyException::DISABLED, 'message' => self::message(PasskeyException::DISABLED)], 403)
                : $this->responder->redirect($request, $this->responder->route()->url());
        }
        if (! $request->isPost()) {
            return $this->screen($request);
        }
        return match ($request->input('portier_step')) {
            'options' => $this->options($request),
            'verify' => $this->verify($request),
            default => Response::json(['error' => PasskeyException::MALFORMED, 'message' => self::message(PasskeyException::MALFORMED)], 400),
        };
    }

    /**
     * Screen for the login page's passkey button and for direct links.
     */
    private function screen(Request $request): Response
    {
        $redirectTo = RedirectResolver::sanitizeRequested($request->query('redirect_to'));
        $route = $this->responder->route();
        $pending = $this->pendingUser($request);
        return $this->responder->page($request, 'passkey', 'passkey', __('Log in with a passkey', 'portier'), [
            'redirect_to' => $redirectTo,
            'remember' => $request->query('rememberme') !== '',
            // From the second-factor screen back there; otherwise to the login.
            'other_url' => $pending !== null ? $route->url('2fa') : $route->url('login', array_filter(['redirect_to' => $redirectTo])),
            'back' => $pending !== null ? $route->url('2fa') : $route->url('login', array_filter(['redirect_to' => $redirectTo])),
        ]);
    }

    private function options(Request $request): Response
    {
        $key = 'passkey-options|' . $this->clientIp;
        if ($this->limiter->lockedFor($key) > 0) {
            return $this->error(PasskeyException::LIMIT, 429);
        }
        $this->limiter->hit($key);

        $binding = (string) $request->cookie(ChallengeStore::COOKIE);
        $newBinding = $binding === '' || strlen($binding) > 64;
        if ($newBinding) {
            $binding = bin2hex(random_bytes(24));
        }
        try {
            $options = $this->passkeys->loginOptions($binding, $this->pendingUser($request));
        } catch (PasskeyException $exception) {
            return $this->error($exception->reason, 400);
        }
        $response = Response::json(['options' => $options]);
        return $newBinding ? $this->responder->withCookie($response, ChallengeStore::COOKIE, $binding) : $response;
    }

    private function verify(Request $request): Response
    {
        $key = 'passkey-verify|' . $this->clientIp;
        if ($this->limiter->lockedFor($key) > 0) {
            return $this->error(PasskeyException::LIMIT, 429);
        }
        $credential = json_decode($request->input('credential'), true);
        try {
            $user = $this->passkeys->login((string) $request->cookie(ChallengeStore::COOKIE), is_array($credential) ? $credential : []);
        } catch (PasskeyException $exception) {
            $this->limiter->hit($key);
            /**
             * Fires when a passkey login fails – for logging. The reason is one of the
             * PasskeyException constants.
             *
             * @param string $reason
             * @param string $details Technical message (not shown to the visitor).
             */
            do_action('portier_passkey_login_failed', $exception->reason, $exception->getMessage());
            return $this->error($exception->reason, $exception->reason === PasskeyException::UNKNOWN ? 404 : 400);
        }

        // A login waiting for its second factor (same account): keep its choices, close it.
        $pendingToken = $request->cookie(PendingLogin::COOKIE);
        $pending = $this->flow->pending()->get($pendingToken);
        $remember = $request->input('remember') !== '';
        $redirect = RedirectResolver::sanitizeRequested($request->input('redirect_to'));
        if ($pending !== null && $pending['user_id'] === $user->ID) {
            $remember = $pending['remember'];
            $redirect = $pending['redirect'];
            $this->flow->pending()->delete((string) $pendingToken);
        }

        $result = $this->flow->complete($user, $remember, $redirect, 'passkey');
        $response = Response::json(['redirect' => (string) $result->redirect]);
        return $pending !== null ? $this->responder->withoutCookie($response, PendingLogin::COOKIE) : $response;
    }

    /**
     * Account of a login waiting for its second factor (the passkey must belong to it).
     */
    private function pendingUser(Request $request): ?WP_User
    {
        $pending = $this->flow->pending()->get($request->cookie(PendingLogin::COOKIE));
        if ($pending === null || $pending['stage'] !== PendingLogin::STAGE_VERIFY) {
            return null;
        }
        $user = get_userdata($pending['user_id']);
        return $user instanceof WP_User && $this->passkeys->credentials()->count($user->ID) > 0 ? $user : null;
    }

    /**
     * Whether the second-factor screen should offer "Use a passkey".
     */
    public function offerOnSecondFactor(int $userId): bool
    {
        return $this->passkeys->enabled() && $this->passkeys->credentials()->count($userId) > 0;
    }

    private function error(string $reason, int $status): Response
    {
        return Response::json(['error' => $reason, 'message' => self::message($reason)], $status);
    }

    /**
     * Message for the visitor. Never reveals more than needed (e.g. which accounts exist).
     */
    public static function message(string $reason): string
    {
        return match ($reason) {
            PasskeyException::CHALLENGE => __('The request has expired. Please try again.', 'portier'),
            PasskeyException::UNKNOWN => __('This passkey is not linked to an account here. Log in with your password and add the passkey in your profile.', 'portier'),
            PasskeyException::VERIFICATION => __('Your device did not confirm it is you. Please try again.', 'portier'),
            PasskeyException::DISABLED => __('Passkeys are not available on this site.', 'portier'),
            PasskeyException::LIMIT => __('Too many attempts. Please wait a few minutes and try again.', 'portier'),
            PasskeyException::BLOCKED => __('This account cannot log in at the moment.', 'portier'),
            default => __('The passkey could not be checked. Please try again or choose another method.', 'portier'),
        };
    }
}
