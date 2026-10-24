<?php

declare(strict_types=1);

namespace Portier\Passkeys;

use Portier\Auth\RecentLogin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

/**
 * REST endpoints for managing passkeys in the profile (portier/v1/passkeys).
 *
 * Adding a passkey is only possible for one's own account, in the current session (the
 * challenge is bound to it) and shortly after logging in ({@see RecentLogin}). Listing, renaming
 * and deleting are allowed for the account owner and for users who may edit that account (e.g.
 * an administrator after a lost device).
 */
final class PasskeyRestController
{
    private const NAMESPACE = 'portier/v1';

    public const REAUTH_ERROR = 'portier_reauth_required';

    public function __construct(
        private readonly PasskeyService $passkeys,
        private readonly RecentLogin $recentLogin,
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        $user = ['user' => ['type' => 'integer', 'required' => false, 'minimum' => 1]];
        $id = '(?P<id>[A-Za-z0-9_-]{1,1400})';

        register_rest_route(self::NAMESPACE, '/passkeys', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'list'],
                'permission_callback' => [$this, 'canManage'],
                'args' => $user,
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'create'],
                'permission_callback' => [$this, 'isLoggedIn'],
                'args' => [
                    'credential' => ['type' => 'object', 'required' => true],
                    'name' => ['type' => 'string', 'required' => false, 'maxLength' => 60],
                ],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/passkeys/options', [
            'methods' => 'POST',
            'callback' => [$this, 'options'],
            'permission_callback' => [$this, 'isLoggedIn'],
        ]);
        register_rest_route(self::NAMESPACE, '/passkeys/' . $id, [
            [
                'methods' => 'POST',
                'callback' => [$this, 'rename'],
                'permission_callback' => [$this, 'canManage'],
                'args' => $user + ['name' => ['type' => 'string', 'required' => true, 'maxLength' => 60]],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'delete'],
                'permission_callback' => [$this, 'canManage'],
                'args' => $user,
            ],
        ]);
    }

    public function isLoggedIn(): bool
    {
        return is_user_logged_in();
    }

    /**
     * Own account, or an account the current user may edit.
     */
    public function canManage(WP_REST_Request $request): bool
    {
        $userId = $this->userId($request);
        return is_user_logged_in() && ($userId === get_current_user_id() || current_user_can('edit_user', $userId));
    }

    public function list(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response(['passkeys' => $this->rows($this->userId($request))]);
    }

    public function options(): WP_REST_Response|WP_Error
    {
        if (! $this->recentLogin->isRecent()) {
            return $this->reauthError();
        }
        try {
            return new WP_REST_Response(['options' => $this->passkeys->registrationOptions(wp_get_current_user())]);
        } catch (PasskeyException $exception) {
            return $this->error($exception);
        }
    }

    public function create(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (! $this->recentLogin->isRecent()) {
            return $this->reauthError();
        }
        $user = wp_get_current_user();
        $credential = $request->get_param('credential');
        try {
            $this->passkeys->register(
                $user,
                is_array($credential) ? $credential : [],
                (string) $request->get_param('name'),
                (string) $request->get_header('user_agent')
            );
        } catch (PasskeyException $exception) {
            return $this->error($exception);
        }
        return new WP_REST_Response(['passkeys' => $this->rows($user->ID)], 201);
    }

    public function rename(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $userId = $this->userId($request);
        if (! $this->passkeys->credentials()->rename($userId, (string) $request->get_param('id'), (string) $request->get_param('name'))) {
            return new WP_Error('portier_passkey_not_found', __('This passkey does not exist (any more).', 'portier'), ['status' => 404]);
        }
        return new WP_REST_Response(['passkeys' => $this->rows($userId)]);
    }

    public function delete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $userId = $this->userId($request);
        if (! $this->passkeys->credentials()->delete($userId, (string) $request->get_param('id'))) {
            return new WP_Error('portier_passkey_not_found', __('This passkey does not exist (any more).', 'portier'), ['status' => 404]);
        }
        return new WP_REST_Response(['passkeys' => $this->rows($userId)]);
    }

    /**
     * Passkeys as shown in the profile (dates formatted for the site).
     *
     * @return list<array{id: string, name: string, created: string, used: string, synced: bool}>
     */
    public function rows(int $userId): array
    {
        $format = get_option('date_format') . ' ' . get_option('time_format');
        $rows = [];
        foreach ($this->passkeys->credentials()->all($userId) as $id => $passkey) {
            $rows[] = [
                'id' => $id,
                'name' => $passkey['name'],
                'created' => $passkey['created'] > 0 ? (string) wp_date($format, $passkey['created']) : '',
                'used' => $passkey['used'] > 0 ? (string) wp_date($format, $passkey['used']) : '',
                'synced' => $passkey['synced'],
            ];
        }
        return $rows;
    }

    private function userId(WP_REST_Request $request): int
    {
        $user = (int) $request->get_param('user');
        return $user > 0 ? $user : get_current_user_id();
    }

    /**
     * A passkey is a permanent way in: an older session must log in again first.
     */
    private function reauthError(): WP_Error
    {
        return new WP_Error(
            self::REAUTH_ERROR,
            __('For your security, please log in again before adding a passkey.', 'portier'),
            ['status' => 403, 'reauth_url' => $this->recentLogin->confirmUrl(admin_url('profile.php') . '#portier-passkeys')]
        );
    }

    private function error(PasskeyException $exception): WP_Error
    {
        $messages = [
            PasskeyException::DISABLED => __('Passkeys are not available on this site.', 'portier'),
            PasskeyException::LIMIT => sprintf(
                /* translators: %d: maximum number of passkeys */
                __('You can add up to %d passkeys. Delete one you no longer use first.', 'portier'),
                CredentialStore::MAX_PER_USER
            ),
            PasskeyException::CHALLENGE => __('The request has expired. Please try again.', 'portier'),
            PasskeyException::VERIFICATION => __('Your device did not confirm it is you. Please try again.', 'portier'),
        ];
        return new WP_Error(
            'portier_passkey_' . $exception->reason,
            $messages[$exception->reason] ?? __('The passkey could not be added. Please try again.', 'portier'),
            ['status' => $exception->reason === PasskeyException::DISABLED ? 403 : 400]
        );
    }
}
