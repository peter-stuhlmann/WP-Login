<?php

declare(strict_types=1);

namespace Portier\Frontend;

use Portier\Auth\AuthResult;
use Portier\Auth\LoginFlow;
use Portier\Auth\PendingLogin;
use Portier\Auth\RecentLogin;
use Portier\Http\Request;
use Portier\Http\Response;
use Portier\Security\LoginThrottle;
use Portier\TwoFactor\CodeCheck;
use Portier\TwoFactor\Totp;
use Portier\TwoFactor\TwoFactorService;
use WP_User;

/**
 * Two-factor screens: enter a code, use a recovery code, set up an authenticator app and save
 * the recovery codes.
 *
 * They serve two situations:
 * - during login (a {@see PendingLogin} exists; nobody is logged in yet),
 * - from the user's profile while logged in (setup / new recovery codes, protected by a nonce).
 */
final class TwoFactorScreens
{
    public const NONCE_SETUP = 'portier-2fa-setup';
    public const NONCE_CODES = 'portier-2fa-codes';

    /** Recovery codes waiting to be shown once to a logged-in user (profile flow). */
    private const CODES_TRANSIENT = 'portier_2fa_codes_';

    /**
     * @param (\Closure(int): bool)|null $offerPasskey Whether to offer "Use a passkey" for a user.
     */
    public function __construct(
        private readonly Responder $responder,
        private readonly LoginFlow $flow,
        private readonly RecentLogin $recentLogin,
        private readonly ?\Closure $offerPasskey = null,
    ) {
    }

    // ------------------------------------------------------------------ Verify

    public function verify(Request $request): Response
    {
        [$token, $state, $user] = $this->pendingLogin($request, PendingLogin::STAGE_VERIFY);
        if ($user === null) {
            return $this->expired($request);
        }

        $errors = $this->unreadableError($user);
        if ($request->isPost() && $errors === []) {
            $tokenError = $this->responder->verifyToken($request, '2fa');
            if ($tokenError !== null) {
                $errors = [AuthResult::FORM => $tokenError];
            } else {
                $check = $this->twoFactor()->check($user, TwoFactorService::TOTP, $request->input('code'));
                if ($check->isValid()) {
                    return $this->finish($request, (string) $token, $state, $user, $request->input('trust_device') !== '', 'totp');
                }
                $errors = $this->checkErrors((string) $token, 'code', $check);
            }
            if ($errors === []) {
                return $this->responder->withoutCookie($this->responder->toLogin($request, 'too-many-codes'), PendingLogin::COOKIE);
            }
        }

        return $this->responder->page($request, '2fa', 'two-factor', __('Two-factor authentication', 'portier'), [
            'errors' => $errors,
            'trust_days' => $this->twoFactor()->trustDays(),
            // A passkey replaces the code (Figma "Passkey nutzen").
            'passkey_url' => $this->offerPasskey !== null && ($this->offerPasskey)($user->ID) ? $this->responder->route()->url('passkey') : '',
        ], $errors === [] ? 200 : 422);
    }

    public function recovery(Request $request): Response
    {
        [$token, $state, $user] = $this->pendingLogin($request, PendingLogin::STAGE_VERIFY);
        if ($user === null) {
            return $this->expired($request);
        }

        $errors = $this->unreadableError($user);
        if ($request->isPost() && $errors === []) {
            $tokenError = $this->responder->verifyToken($request, 'recovery');
            if ($tokenError !== null) {
                $errors = [AuthResult::FORM => $tokenError];
            } else {
                $check = $this->twoFactor()->check($user, TwoFactorService::RECOVERY, $request->input('recovery_code'));
                if ($check->isValid()) {
                    return $this->finish($request, (string) $token, $state, $user, false, 'recovery');
                }
                $errors = $this->checkErrors((string) $token, 'recovery_code', $check);
            }
            if ($errors === []) {
                return $this->responder->withoutCookie($this->responder->toLogin($request, 'too-many-codes'), PendingLogin::COOKIE);
            }
        }

        return $this->responder->page($request, 'recovery', 'recovery', __('Use a recovery code', 'portier'), [
            'errors' => $errors,
            'values' => ['recovery_code' => $request->input('recovery_code')],
        ], $errors === [] ? 200 : 422);
    }

    // ------------------------------------------------------------------ Setup

    public function setup(Request $request): Response
    {
        [$token, , $user] = $this->pendingLogin($request, PendingLogin::STAGE_SETUP);
        $fromProfile = false;
        if ($user === null) {
            $user = $this->profileUser($request, self::NONCE_SETUP);
            $fromProfile = true;
        }
        if ($user === null) {
            return $this->expired($request);
        }
        if ($fromProfile && ! $this->recentLogin->isRecent()) {
            return $this->confirmIdentity($request);
        }
        $nonceArgs = $fromProfile ? ['_wpnonce' => $request->query('_wpnonce')] : [];

        $errors = [];
        if ($request->isPost()) {
            $tokenError = $this->responder->verifyToken($request, '2fa-setup');
            $codes = $tokenError === null ? $this->twoFactor()->confirmSetup($user, $request->input('code')) : null;
            if ($codes !== null) {
                if ($fromProfile) {
                    set_transient(self::CODES_TRANSIENT . $user->ID, $codes, 10 * MINUTE_IN_SECONDS);
                    $next = $this->responder->route()->url('recovery-codes', ['_wpnonce' => wp_create_nonce(self::NONCE_CODES)]);
                } else {
                    $this->flow->pending()->update((string) $token, ['stage' => PendingLogin::STAGE_CODES, 'codes' => $codes]);
                    $next = $this->responder->route()->url('recovery-codes');
                }
                return $this->responder->redirect($request, $next);
            }
            $errors = $tokenError !== null
                ? [AuthResult::FORM => $tokenError]
                : ['code' => __('This code is not correct. Please check the time on your phone and enter the current code.', 'portier')];
        }

        $secret = $this->twoFactor()->store()->pendingSecret($user->ID);
        return $this->responder->page($request, '2fa-setup', 'two-factor-setup', __('Set up two-factor authentication', 'portier'), [
            'errors' => $errors,
            'secret' => $secret,
            'secret_grouped' => trim(chunk_split($secret, 4, ' ')),
            'otpauth' => Totp::uri($secret, $user->user_email, wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES)),
            'required' => $this->twoFactor()->isRequiredFor($user) && ! $fromProfile,
        ], $errors === [] ? 200 : 422, $nonceArgs);
    }

    /**
     * Shows recovery codes exactly once – after setup (login or profile) or when a logged-in
     * user creates new ones (?regenerate=1).
     */
    public function codes(Request $request): Response
    {
        [$token, $state, $pendingUser] = $this->pendingLogin($request, PendingLogin::STAGE_CODES);
        $profileUser = $pendingUser === null ? $this->profileUser($request, self::NONCE_CODES) : null;
        $user = $pendingUser ?? $profileUser;
        if ($user === null) {
            return $this->expired($request);
        }

        // New codes for a logged-in user (from the profile): create, then show via PRG.
        if ($profileUser !== null && $request->query('regenerate') === '1' && ! $request->isPost()) {
            if (! $this->recentLogin->isRecent()) {
                return $this->confirmIdentity($request);
            }
            if ($this->twoFactor()->store()->isEnabled($user->ID)) {
                set_transient(self::CODES_TRANSIENT . $user->ID, $this->twoFactor()->store()->regenerateRecoveryCodes($user->ID), 10 * MINUTE_IN_SECONDS);
                /** Fires when a user creates new recovery codes (audit log). @param int $userId */
                do_action('portier_2fa_codes_renewed', $user->ID);
            }
            return Response::redirect($this->responder->route()->url('recovery-codes', ['_wpnonce' => $request->query('_wpnonce')]), 303);
        }

        $codes = $pendingUser !== null ? ($state['codes'] ?? []) : get_transient(self::CODES_TRANSIENT . $user->ID);
        if (! is_array($codes) || $codes === []) {
            return $this->expired($request);
        }

        $errors = [];
        if ($request->isPost()) {
            $tokenError = $this->responder->verifyToken($request, 'recovery-codes');
            if ($tokenError !== null) {
                $errors = [AuthResult::FORM => $tokenError];
            } elseif ($request->input('codes_saved') === '') {
                $errors = ['codes_saved' => __('Please confirm that you have saved your recovery codes.', 'portier')];
            } elseif ($pendingUser !== null) {
                return $this->finish($request, (string) $token, $state, $user, false, 'totp-setup');
            } else {
                delete_transient(self::CODES_TRANSIENT . $user->ID);
                return $this->responder->redirect($request, add_query_arg('portier-2fa', 'updated', self_admin_url('profile.php')));
            }
        }

        return $this->responder->page($request, 'recovery-codes', 'recovery-codes', __('Save your recovery codes', 'portier'), [
            'errors' => $errors,
            'codes' => array_values($codes),
            'from_login' => $pendingUser !== null,
        ], $errors === [] ? 200 : 422, $profileUser !== null ? ['_wpnonce' => $request->query('_wpnonce')] : []);
    }

    // ------------------------------------------------------------------ Helpers

    private function twoFactor(): TwoFactorService
    {
        return $this->flow->twoFactor();
    }

    /**
     * @return array{0: ?string, 1: ?array<string, mixed>, 2: ?WP_User}
     */
    private function pendingLogin(Request $request, string $stage): array
    {
        $token = $request->cookie(PendingLogin::COOKIE);
        $state = $this->flow->pending()->get($token);
        if ($state === null || $state['stage'] !== $stage) {
            return [null, null, null];
        }
        $user = get_userdata($state['user_id']);
        return $user instanceof WP_User ? [$token, $state, $user] : [null, null, null];
    }

    /**
     * Logged-in user coming from the profile (the nonce proves they came from there).
     */
    private function profileUser(Request $request, string $nonceAction): ?WP_User
    {
        if (! is_user_logged_in() || ! wp_verify_nonce($request->query('_wpnonce'), $nonceAction)) {
            return null;
        }
        return wp_get_current_user();
    }

    /**
     * Errors for a code that was not accepted, or [] when the pending login's attempts are used up.
     *
     * @return array<string, string>
     */
    private function checkErrors(string $token, string $field, CodeCheck $check): array
    {
        if ($check->status === CodeCheck::UNREADABLE) {
            return [AuthResult::FORM => self::unreadableMessage()];
        }
        if ($check->status === CodeCheck::BUSY) {
            return [AuthResult::FORM => __('Your previous code is still being checked. Please try again in a moment.', 'portier')];
        }
        if ($check->status === CodeCheck::WRONG) {
            $errors = $this->wrongCode($token, $field);
            if ($errors === [] || ! $check->isPaused()) {
                return $errors;
            }
        }
        // Paused for this account: no code is checked, whichever login it comes from.
        return [AuthResult::FORM => LoginThrottle::lockedMessage($check->retryAfter)];
    }

    /**
     * The secret cannot be decrypted any more: no code can work, so none is asked for.
     *
     * @return array<string, string>
     */
    private function unreadableError(WP_User $user): array
    {
        return $this->twoFactor()->isUnreadable($user) ? [AuthResult::FORM => self::unreadableMessage()] : [];
    }

    private static function unreadableMessage(): string
    {
        return __(
            'Your two-factor authentication can no longer be read because the site’s security keys have changed. Please ask an administrator to reset it for your account.',
            'portier'
        );
    }

    /**
     * Sensitive change from the profile in an older session: log in again first.
     */
    private function confirmIdentity(Request $request): Response
    {
        return $this->responder->redirect($request, $this->recentLogin->confirmUrl(self_admin_url('profile.php') . '#portier-2fa'));
    }

    /**
     * Counts a wrong code. Returns the field error, or [] when the attempts are used up.
     *
     * @return array<string, string>
     */
    private function wrongCode(string $token, string $field): array
    {
        $state = $this->flow->pending()->get($token);
        /**
         * Fires when a wrong two-factor or recovery code was entered (audit log).
         *
         * @param int    $userId
         * @param string $field "code" or "recovery_code".
         */
        do_action('portier_2fa_failed', (int) ($state['user_id'] ?? 0), $field);
        $left = $this->flow->pending()->fail($token);
        if ($left === 0) {
            return [];
        }
        return [
            $field => sprintf(
                /* translators: %d: remaining attempts */
                _n('This code is not correct. %d attempt left.', 'This code is not correct. %d attempts left.', $left, 'portier'),
                $left
            ),
        ];
    }

    /**
     * Second factor done: start the session, optionally remember the device.
     *
     * @param array<string, mixed>|null $state
     */
    private function finish(Request $request, string $token, ?array $state, WP_User $user, bool $trustDevice, string $secondFactor): Response
    {
        $this->flow->pending()->delete($token);
        $method = (string) ($state['method'] ?? 'password') . '+' . $secondFactor;
        $result = $this->flow->complete($user, (bool) ($state['remember'] ?? false), (string) ($state['redirect'] ?? ''), $method);
        $response = $this->responder->withoutCookie($this->responder->redirect($request, (string) $result->redirect), PendingLogin::COOKIE);
        if ($trustDevice && $this->twoFactor()->trustDays() > 0) {
            $response = $this->responder->withCookie(
                $response,
                TwoFactorService::DEVICE_COOKIE,
                $this->twoFactor()->trustDevice($user),
                time() + $this->twoFactor()->trustDays() * DAY_IN_SECONDS
            );
        }
        return $response;
    }

    private function expired(Request $request): Response
    {
        return $this->responder->withoutCookie($this->responder->toLogin($request, 'session-expired'), PendingLogin::COOKIE);
    }
}
