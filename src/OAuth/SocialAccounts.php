<?php

declare(strict_types=1);

namespace Portier\OAuth;

use Portier\Auth\PasswordlessLogin;
use Portier\Auth\RegistrationService;
use Portier\Auth\UsernameGenerator;
use WP_Error;
use WP_User;

/**
 * Connects provider identities with WordPress accounts.
 *
 * Rules, in this order:
 * 1. A provider account that was linked before logs into its WordPress account (by subject id).
 * 2. Otherwise an existing account is linked only if the provider *verified* the same e-mail
 *    address and automatic linking is allowed for the provider.
 * 3. Otherwise a new account is created only if registration is open and the provider allows
 *    sign-ups – again only with a verified e-mail address.
 * Accounts with a blocked role (administrators by default) never log in socially.
 */
final class SocialAccounts
{
    /** User meta holding the provider subject (one key per provider, indexed lookups). */
    private const SUBJECT_META = 'portier_sub_';
    /** User meta with display details of a connection (e-mail, connected at). */
    private const DETAILS_META = 'portier_social_';

    /**
     * @param list<string> $blockedRoles
     */
    public function __construct(private readonly array $blockedRoles)
    {
    }

    /**
     * The WordPress account for a provider identity (rules 1–3 above).
     *
     * @throws OAuthException
     */
    public function resolve(Identity $identity, ProviderConfig $config): WP_User
    {
        $user = $this->find($identity->provider, $identity->subject);
        if ($user === null) {
            if ($identity->email === '' || ! $identity->emailVerified) {
                throw new OAuthException(esc_html(OAuthException::EMAIL), esc_html('Provider did not confirm an e-mail address.'));
            }
            $existing = get_user_by('email', $identity->email);
            if ($existing instanceof WP_User) {
                if ($config->linkPolicy() !== ProviderConfig::LINK_VERIFIED) {
                    throw new OAuthException(esc_html(OAuthException::LINK_DISABLED));
                }
                $this->assertAllowed($existing, $identity->provider);
                $this->link($existing, $identity);
                $user = $existing;
            } else {
                if (! $config->signupAllowed() || ! RegistrationService::isOpen()) {
                    throw new OAuthException(esc_html(OAuthException::NO_ACCOUNT));
                }
                $user = $this->create($identity);
            }
        }
        $this->assertAllowed($user, $identity->provider);
        return $user;
    }

    /**
     * Account currently linked to a provider subject.
     */
    public function find(string $provider, string $subject): ?WP_User
    {
        // phpcs:ignore WordPress.DB.SlowDBQuery -- Indexed meta key, exact value, at most two rows.
        $users = get_users(['meta_key' => self::SUBJECT_META . $provider, 'meta_value' => $subject, 'number' => 2, 'blog_id' => 0]);
        // Two accounts with the same subject cannot happen through Portier; refuse if it ever does.
        return count($users) === 1 && $users[0] instanceof WP_User ? $users[0] : null;
    }

    /**
     * Connects a provider account with a user (from the profile or after a verified match) and
     * tells the owner by e-mail.
     *
     * @throws OAuthException When the provider account belongs to someone else.
     */
    public function link(WP_User $user, Identity $identity): void
    {
        $this->connect($user, $identity);
        $this->notifyLinked($user, $identity);
    }

    /**
     * Stores the connection (without telling the owner – used for accounts created just now).
     *
     * @throws OAuthException When the provider account belongs to someone else.
     */
    private function connect(WP_User $user, Identity $identity): void
    {
        $owner = $this->find($identity->provider, $identity->subject);
        if ($owner !== null && $owner->ID !== $user->ID) {
            throw new OAuthException(esc_html(OAuthException::LINKED_ELSEWHERE));
        }
        update_user_meta($user->ID, self::SUBJECT_META . $identity->provider, $identity->subject);
        update_user_meta($user->ID, self::DETAILS_META . $identity->provider, [
            'email' => $identity->email,
            'name' => $identity->name,
            'linked' => time(),
        ]);
        /**
         * Fires after a social account was connected with a WordPress account.
         */
        do_action('portier_social_linked', $user, $identity);
    }

    public function unlink(WP_User $user, string $provider): void
    {
        delete_user_meta($user->ID, self::SUBJECT_META . $provider);
        delete_user_meta($user->ID, self::DETAILS_META . $provider);
        do_action('portier_social_unlinked', $user, $provider);
    }

    /**
     * Connections of a user, keyed by provider id.
     *
     * @return array<string, array{email: string, name: string, linked: int}>
     */
    public function connections(WP_User $user): array
    {
        $connections = [];
        foreach (Providers::all() as $id => $provider) {
            if ((string) get_user_meta($user->ID, self::SUBJECT_META . $id, true) === '') {
                continue;
            }
            $details = get_user_meta($user->ID, self::DETAILS_META . $id, true);
            $details = is_array($details) ? $details : [];
            $connections[$id] = [
                'email' => (string) ($details['email'] ?? ''),
                'name' => (string) ($details['name'] ?? ''),
                'linked' => (int) ($details['linked'] ?? 0),
            ];
        }
        return $connections;
    }

    /**
     * Whether the user may log in with a social account at all.
     */
    public function isAllowed(WP_User $user, string $provider): bool
    {
        $blocked = array_intersect($this->blockedRoles, $user->roles) !== []
            || (in_array('administrator', $this->blockedRoles, true) && is_multisite() && is_super_admin($user->ID))
            || ! PasswordlessLogin::allowed($user, 'social:' . $provider);
        /**
         * Filters whether a user may log in with a social account.
         *
         * @param bool    $allowed
         * @param WP_User $user
         * @param string  $provider Provider id.
         */
        return (bool) apply_filters('portier_social_login_allowed', ! $blocked, $user, $provider);
    }

    /**
     * @throws OAuthException
     */
    private function assertAllowed(WP_User $user, string $provider): void
    {
        if (! $this->isAllowed($user, $provider)) {
            throw new OAuthException(esc_html(OAuthException::BLOCKED));
        }
    }

    /**
     * Tells the owner about a new connection – if it was not them, they can react.
     */
    private function notifyLinked(WP_User $user, Identity $identity): void
    {
        $switched = switch_to_user_locale($user->ID);
        $site = wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES);
        $provider = Providers::get($identity->provider)->label ?? $identity->provider;
        /* translators: 1: site name, 2: provider name, e.g. Google */
        $subject = sprintf(__('[%1$s] %2$s account connected', 'portier'), $site, $provider);
        $paragraphs = [
            /* translators: %s: user display name */
            sprintf(__('Hi %s,', 'portier'), $user->display_name),
            sprintf(
                /* translators: 1: provider name, e.g. Google, 2: site name */
                __('a %1$s account was connected to your account on %2$s. From now on it can be used to log in.', 'portier'),
                $provider,
                $site
            ),
            __('If this was not you, disconnect it in your profile right away and change your password:', 'portier'),
            admin_url('profile.php#portier-social'),
        ];
        wp_mail($user->user_email, $subject, implode("\n\n", $paragraphs));
        if ($switched) {
            restore_previous_locale();
        }
    }

    /**
     * Creates an account for a verified identity, with the site's default role.
     *
     * @throws OAuthException
     */
    private function create(Identity $identity): WP_User
    {
        $login = UsernameGenerator::fromEmail($identity->email, static fn (string $name): bool => username_exists($name) !== false);
        $display = $identity->name !== '' ? $identity->name : $login;

        if (! is_multisite()) {
            // Anti-spam plugins hook in here, like for the registration form.
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, fired for compatibility.
            $errors = apply_filters('registration_errors', new WP_Error(), $login, $identity->email);
            if ($errors instanceof WP_Error && $errors->has_errors()) {
                throw new OAuthException(esc_html(OAuthException::NO_ACCOUNT), esc_html('Blocked by registration_errors: ' . implode(', ', $errors->get_error_codes())));
            }
        }

        $userId = wp_insert_user([
            'user_login' => $login,
            'user_email' => $identity->email,
            // Random password nobody knows; "forgot password" sets a real one if ever needed.
            'user_pass' => wp_generate_password(32, true, true),
            'display_name' => $display,
            'nickname' => $display,
            'first_name' => $identity->firstName,
            'last_name' => $identity->lastName,
            'role' => is_multisite() ? '' : (string) get_option('default_role', 'subscriber'),
        ]);
        if ($userId instanceof WP_Error) {
            throw new OAuthException(esc_html(OAuthException::NO_ACCOUNT), esc_html('Account could not be created: ' . $userId->get_error_code()));
        }
        if (is_multisite()) {
            add_user_to_blog(get_current_blog_id(), $userId, (string) get_option('default_role', 'subscriber'));
        }
        $user = new WP_User($userId);
        $this->connect($user, $identity);
        // Tell the site owner like core does for new registrations (no password e-mail to the user).
        wp_new_user_notification($userId, null, 'admin');
        /**
         * Fires after an account was created through a social login.
         */
        do_action('portier_social_registered', $user, $identity);
        return $user;
    }
}
