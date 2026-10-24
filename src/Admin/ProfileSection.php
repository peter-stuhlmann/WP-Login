<?php

declare(strict_types=1);

namespace Portier\Admin;

use Portier\Frontend\TwoFactorScreens;
use Portier\Routing\LoginRoute;
use Portier\TwoFactor\TwoFactorService;
use WP_Error;
use WP_User;

/**
 * "Two-factor authentication" section on the user profile screen.
 *
 * Own profile: status, set up, new recovery codes, turn off (requires the current password, so
 * someone at an unlocked computer cannot simply remove the protection).
 * Other users (administrators with edit_users): reset 2FA, e.g. when the phone was lost.
 */
final class ProfileSection
{
    private const NONCE = 'portier-2fa-profile';

    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly LoginRoute $route,
    ) {
    }

    public function register(): void
    {
        add_action('show_user_profile', [$this, 'render']);
        add_action('edit_user_profile', [$this, 'render']);
        add_action('user_profile_update_errors', [$this, 'validate'], 10, 3);
        add_action('personal_options_update', [$this, 'save']);
        add_action('edit_user_profile_update', [$this, 'save']);
        add_action('admin_notices', [$this, 'notice']);
        // A new password often follows a compromised account: forget all trusted devices – after
        // a reset (wp_set_password) as well as after a change in the profile (wp_update_user).
        add_action('wp_set_password', [$this, 'passwordSet'], 10, 2);
        add_action('profile_update', [$this, 'profileUpdated'], 10, 2);
    }

    public function passwordSet(string $password, int $userId): void
    {
        $this->twoFactor->store()->forgetDevices($userId);
    }

    public function profileUpdated(int $userId, WP_User $before): void
    {
        $user = get_userdata($userId);
        if ($user instanceof WP_User && $user->user_pass !== $before->user_pass) {
            $this->twoFactor->store()->forgetDevices($userId);
        }
    }

    public function render(WP_User $user): void
    {
        if (! $this->twoFactor->isActive()) {
            return;
        }
        $own = $user->ID === get_current_user_id();
        $enabled = $this->twoFactor->store()->isEnabled($user->ID);
        $setupUrl = $this->route->url('2fa-setup', ['_wpnonce' => wp_create_nonce(TwoFactorScreens::NONCE_SETUP)]);
        $codesUrl = $this->route->url('recovery-codes', ['regenerate' => 1, '_wpnonce' => wp_create_nonce(TwoFactorScreens::NONCE_CODES)]);
        $required = $this->twoFactor->isRequiredFor($user);
        wp_nonce_field(self::NONCE, '_portier_2fa_nonce');
        ?>
        <h2 id="portier-2fa"><?php esc_html_e('Two-factor authentication', 'portier'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Status', 'portier'); ?></th>
                <td>
                    <p>
                        <strong>
                            <?php echo $enabled ? esc_html__('Active – protected with an authenticator app.', 'portier') : esc_html__('Not set up.', 'portier'); ?>
                        </strong>
                        <?php if ($required) : ?>
                            <br><?php esc_html_e('Required for this account’s role.', 'portier'); ?>
                        <?php endif; ?>
                    </p>
                    <?php if ($this->twoFactor->isUnreadable($user)) : ?>
                        <p class="description">
                            <?php
                            esc_html_e(
                                'It can no longer be read because the site’s security keys changed. Turn it off and set it up again, or ask an administrator to reset it.',
                                'portier'
                            );
                            ?>
                        </p>
                    <?php elseif ($enabled) : ?>
                        <p class="description">
                            <?php
                            $left = $this->twoFactor->store()->recoveryCodesLeft($user->ID);
                            /* translators: %d: number of unused recovery codes */
                            echo esc_html(sprintf(_n('%d recovery code left.', '%d recovery codes left.', $left, 'portier'), $left));
                            ?>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>
            <?php if ($own && ! $enabled) : ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Set up', 'portier'); ?></th>
                    <td>
                        <a class="button button-primary" href="<?php echo esc_url($setupUrl); ?>">
                            <?php esc_html_e('Set up two-factor authentication', 'portier'); ?>
                        </a>
                        <p class="description"><?php esc_html_e('You need an authenticator app such as 1Password, Aegis or Google Authenticator.', 'portier'); ?></p>
                    </td>
                </tr>
            <?php elseif ($own) : ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Recovery codes', 'portier'); ?></th>
                    <td>
                        <a class="button" href="<?php echo esc_url($codesUrl); ?>">
                            <?php esc_html_e('Create new recovery codes', 'portier'); ?>
                        </a>
                        <p class="description"><?php esc_html_e('Your old codes stop working.', 'portier'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="portier-2fa-password"><?php esc_html_e('Turn off', 'portier'); ?></label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="portier_2fa_disable" value="1">
                            <?php esc_html_e('Turn off two-factor authentication', 'portier'); ?>
                        </label>
                        <p>
                            <label for="portier-2fa-password"><?php esc_html_e('Current password (required to turn it off)', 'portier'); ?></label><br>
                            <input type="password" id="portier-2fa-password" name="portier_2fa_password" class="regular-text" autocomplete="current-password">
                        </p>
                    </td>
                </tr>
            <?php elseif ($enabled && current_user_can('edit_users')) : ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Reset', 'portier'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="portier_2fa_reset" value="1">
                            <?php esc_html_e('Reset two-factor authentication for this user (e.g. lost phone)', 'portier'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('The user can set it up again at the next login.', 'portier'); ?></p>
                    </td>
                </tr>
            <?php endif; ?>
        </table>
        <?php
    }

    /**
     * Turning 2FA off on the own profile requires the current password.
     *
     * @param WP_Error             $errors
     * @param bool                 $update
     * @param \stdClass|WP_User    $user
     */
    public function validate(WP_Error $errors, bool $update, $user): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified below / by core for the profile form.
        if (! $update || empty($_POST['portier_2fa_disable']) || (int) $user->ID !== get_current_user_id()) {
            return;
        }
        if (! $this->validNonce()) {
            $errors->add('portier_2fa_nonce', __('Two-factor authentication could not be changed. Please try again.', 'portier'));
            return;
        }
        $password = $this->postedPassword();
        $current = get_userdata((int) $user->ID);
        if (! $current instanceof WP_User || ! wp_check_password($password, $current->user_pass, $current->ID)) {
            $errors->add(
                'portier_2fa_password',
                __('To turn off two-factor authentication, please enter your current password.', 'portier'),
                ['form-field' => 'portier_2fa_password']
            );
        }
        // phpcs:enable
    }

    public function save(int $userId): void
    {
        if (! $this->validNonce()) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in validNonce().
        $own = $userId === get_current_user_id();
        if ($own && ! empty($_POST['portier_2fa_disable'])) {
            $password = $this->postedPassword();
            $user = get_userdata($userId);
            if ($user instanceof WP_User && wp_check_password($password, $user->user_pass, $userId)) {
                $this->twoFactor->store()->disable($userId);
            }
        } elseif (! $own && ! empty($_POST['portier_2fa_reset']) && current_user_can('edit_user', $userId)) {
            $this->twoFactor->store()->disable($userId);
        }
        // phpcs:enable
    }

    /**
     * Confirmation after coming back from the recovery codes screen.
     */
    public function notice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
        $flag = isset($_GET['portier-2fa']) ? sanitize_key(wp_unslash($_GET['portier-2fa'])) : '';
        $screen = get_current_screen();
        if ($flag !== 'updated' || $screen === null || ! in_array($screen->id, ['profile', 'profile-network'], true)) {
            return;
        }
        printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__('Two-factor authentication is set up. Your recovery codes are saved.', 'portier'));
    }

    private function validNonce(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is the nonce check.
        $nonce = isset($_POST['_portier_2fa_nonce']) ? sanitize_text_field(wp_unslash($_POST['_portier_2fa_nonce'])) : '';
        return (bool) wp_verify_nonce($nonce, self::NONCE);
    }

    private function postedPassword(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked by the callers; passwords are not sanitized.
        return isset($_POST['portier_2fa_password']) ? (string) wp_unslash($_POST['portier_2fa_password']) : '';
    }
}
