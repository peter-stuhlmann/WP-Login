<?php

declare(strict_types=1);

namespace Portier\Admin;

use Portier\Frontend\SocialScreens;
use Portier\OAuth\OAuthException;
use Portier\OAuth\ProviderConfig;
use Portier\OAuth\Providers;
use Portier\OAuth\SocialAccounts;
use Portier\Routing\LoginRoute;
use Portier\Security\Crypto;
use Portier\Settings\Settings;
use WP_User;

/**
 * "Connected accounts" section on the user profile screen.
 *
 * Own profile: connect a provider (starts the social login in "link" mode) or disconnect it.
 * Other users (edit_user): see and disconnect connections, e.g. after an account takeover.
 */
final class SocialProfileSection
{
    private const NONCE = 'portier-social-profile';

    public function __construct(
        private readonly SocialAccounts $accounts,
        private readonly LoginRoute $route,
        private readonly Settings $settings,
    ) {
    }

    public function register(): void
    {
        add_action('show_user_profile', [$this, 'render'], 11);
        add_action('edit_user_profile', [$this, 'render'], 11);
        add_action('personal_options_update', [$this, 'save']);
        add_action('edit_user_profile_update', [$this, 'save']);
        add_action('admin_notices', [$this, 'notice']);
    }

    public function render(WP_User $user): void
    {
        $own = $user->ID === get_current_user_id();
        $connections = $this->accounts->connections($user);
        $usable = $this->usableProviders();
        $providers = array_filter(
            Providers::all(),
            static fn ($provider): bool => isset($connections[$provider->id]) || ($own && isset($usable[$provider->id]))
        );
        if ($providers === []) {
            return;
        }
        $allowed = $this->accounts->isAllowed($user, '');
        wp_nonce_field(self::NONCE, '_portier_social_nonce');
        ?>
        <h2 id="portier-social"><?php esc_html_e('Connected accounts', 'portier'); ?></h2>
        <?php if (! $allowed) : ?>
            <p class="description">
                <?php esc_html_e('For security reasons, accounts with this role cannot log in with Google, LinkedIn or other social accounts.', 'portier'); ?>
            </p>
        <?php endif; ?>
        <table class="form-table" role="presentation">
            <?php foreach ($providers as $id => $provider) : ?>
                <?php $connection = $connections[$id] ?? null; ?>
                <tr>
                    <th scope="row"><?php echo esc_html($provider->label); ?></th>
                    <td>
                        <?php if ($connection !== null) : ?>
                            <p>
                                <strong><?php esc_html_e('Connected', 'portier'); ?></strong>
                                <?php if ($connection['email'] !== '') : ?>
                                    – <?php echo esc_html($connection['email']); ?>
                                <?php endif; ?>
                                <?php if ($connection['linked'] > 0) : ?>
                                    <br><span class="description">
                                        <?php
                                        /* translators: %s: date */
                                        echo esc_html(sprintf(__('Since %s', 'portier'), wp_date((string) get_option('date_format'), $connection['linked'])));
                                        ?>
                                    </span>
                                <?php endif; ?>
                            </p>
                            <label>
                                <input type="checkbox" name="portier_social_unlink[]" value="<?php echo esc_attr($id); ?>">
                                <?php
                                /* translators: %s: provider name, e.g. Google */
                                echo esc_html(sprintf(__('Disconnect %s', 'portier'), $provider->label));
                                ?>
                            </label>
                        <?php elseif ($allowed) : ?>
                            <a class="button" href="<?php echo esc_url($this->connectUrl($id)); ?>">
                                <?php
                                /* translators: %s: provider name, e.g. Google */
                                echo esc_html(sprintf(__('Connect %s', 'portier'), $provider->label));
                                ?>
                            </a>
                            <p class="description">
                                <?php
                                /* translators: %s: provider name, e.g. Google */
                                echo esc_html(sprintf(__('Afterwards you can log in with %s.', 'portier'), $provider->label));
                                ?>
                            </p>
                        <?php else : ?>
                            <span class="description"><?php esc_html_e('Not connected', 'portier'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        <?php
    }

    public function save(int $userId): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is the nonce check.
        $nonce = isset($_POST['_portier_social_nonce']) ? sanitize_text_field(wp_unslash($_POST['_portier_social_nonce'])) : '';
        if (! wp_verify_nonce($nonce, self::NONCE) || ! current_user_can('edit_user', $userId)) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
        $unlink = isset($_POST['portier_social_unlink']) && is_array($_POST['portier_social_unlink'])
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
            ? array_map('sanitize_key', wp_unslash($_POST['portier_social_unlink']))
            : [];
        $user = get_userdata($userId);
        if (! $user instanceof WP_User) {
            return;
        }
        foreach ($unlink as $provider) {
            if (Providers::get($provider) !== null) {
                $this->accounts->unlink($user, $provider);
            }
        }
    }

    /**
     * Result of connecting a provider (the callback redirects back to the profile).
     */
    public function notice(): void
    {
        $screen = get_current_screen();
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only.
        $flag = isset($_GET['portier-social']) ? sanitize_key(wp_unslash($_GET['portier-social'])) : '';
        $reason = isset($_GET['reason']) ? sanitize_key(wp_unslash($_GET['reason'])) : '';
        $provider = Providers::get(isset($_GET['provider']) ? sanitize_key(wp_unslash($_GET['provider'])) : '');
        // phpcs:enable
        if ($flag === '' || $screen === null || ! in_array($screen->id, ['profile', 'profile-network'], true)) {
            return;
        }
        $label = $provider->label ?? __('this provider', 'portier');
        if ($flag === 'linked') {
            /* translators: %s: provider name, e.g. Google */
            $this->print('success', sprintf(__('%s is now connected. You can use it to log in.', 'portier'), $label));
            return;
        }
        $message = match ($reason) {
            /* translators: %s: provider name, e.g. Google */
            OAuthException::LINKED_ELSEWHERE => __('This %s account is already connected to another account.', 'portier'),
            /* translators: %s: provider name, e.g. Google */
            OAuthException::BLOCKED => __('For security reasons this account cannot log in with %s.', 'portier'),
            /* translators: %s: provider name, e.g. Google */
            OAuthException::CANCELLED => __('Connecting %s was cancelled.', 'portier'),
            /* translators: %s: provider name, e.g. Google */
            default => __('%s could not be connected. Please try again.', 'portier'),
        };
        $this->print('error', sprintf($message, $label));
    }

    private function print(string $type, string $message): void
    {
        printf('<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr($type), esc_html($message));
    }

    private function connectUrl(string $provider): string
    {
        return $this->route->url('oauth', [
            'provider' => $provider,
            'link' => '1',
            '_wpnonce' => wp_create_nonce(SocialScreens::LINK_NONCE),
        ]);
    }

    /**
     * @return array<string, true>
     */
    private function usableProviders(): array
    {
        $crypto = Crypto::fromWordPress();
        $usable = [];
        foreach (Providers::all() as $id => $provider) {
            if (ProviderConfig::for($provider, $this->settings, $crypto)->usable()) {
                $usable[$id] = true;
            }
        }
        return $usable;
    }
}
