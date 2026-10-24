<?php

declare(strict_types=1);

namespace Portier\Admin;

use Portier\Passkeys\PasskeyRestController;
use Portier\Passkeys\PasskeyService;
use WP_User;

/**
 * "Passkeys" section on the user profile screen.
 *
 * Own profile: add a passkey (the browser asks the device), rename and delete. Other users
 * (edit_user): see and delete them, e.g. after a lost device. The list is rendered here so it
 * shows without JavaScript; the profile script (assets/src/profile) does the rest.
 */
final class PasskeyProfileSection
{
    public function __construct(
        private readonly PasskeyService $passkeys,
        private readonly PasskeyRestController $rest,
    ) {
    }

    public function register(): void
    {
        add_action('show_user_profile', [$this, 'render'], 12);
        add_action('edit_user_profile', [$this, 'render'], 12);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(string $hook): void
    {
        if (! in_array($hook, ['profile.php', 'user-edit.php'], true)) {
            return;
        }
        $file = PORTIER_DIR . '/build/profile.asset.php';
        $asset = is_readable($file) ? require $file : ['dependencies' => ['wp-api-fetch', 'wp-i18n'], 'version' => PORTIER_VERSION];
        wp_enqueue_script('portier-profile', plugins_url('build/profile.js', PORTIER_FILE), $asset['dependencies'], $asset['version'], true);
        wp_set_script_translations('portier-profile', 'portier', PORTIER_DIR . '/languages');
        if (is_readable(PORTIER_DIR . '/build/profile.css')) {
            wp_enqueue_style('portier-profile', plugins_url('build/profile.css', PORTIER_FILE), [], $asset['version']);
        }
    }

    public function render(WP_User $user): void
    {
        $own = $user->ID === get_current_user_id();
        $rows = $this->rest->rows($user->ID);
        // Without passkeys on a site where they are off there is nothing to show.
        if (! $this->passkeys->enabled() && $rows === []) {
            return;
        }
        ?>
        <h2 id="portier-passkeys"><?php esc_html_e('Passkeys', 'portier'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Your passkeys', 'portier'); ?></th>
                <td>
                    <div class="portier-passkeys" data-portier-passkeys
                        data-user="<?php echo esc_attr((string) $user->ID); ?>"
                        data-own="<?php echo $own ? '1' : ''; ?>">
                        <p class="description">
                            <?php
                            echo $own
                                ? esc_html__(
                                    'Log in with your fingerprint, face or device PIN – no password, no code. Passkeys only work on this site, so phishing pages cannot use them.',
                                    'portier'
                                )
                                : esc_html__('Passkeys of this account. Delete one if its device was lost.', 'portier');
                            ?>
                        </p>

                        <ul class="portier-passkeys__list" data-portier-passkey-list>
                            <?php foreach ($rows as $row) : ?>
                                <li class="portier-passkeys__item" data-id="<?php echo esc_attr($row['id']); ?>">
                                    <span class="portier-passkeys__name"><?php echo esc_html($row['name']); ?></span>
                                    <span class="portier-passkeys__meta description">
                                        <?php
                                        /* translators: %s: date and time */
                                        echo esc_html(sprintf(__('Added %s', 'portier'), $row['created']));
                                        ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="portier-passkeys__empty description" data-portier-passkey-empty<?php echo $rows !== [] ? ' hidden' : ''; ?>>
                            <?php esc_html_e('No passkeys yet.', 'portier'); ?>
                        </p>

                        <?php if ($own && $this->passkeys->enabled()) : ?>
                            <div class="portier-passkeys__add" data-portier-passkey-add hidden>
                                <label for="portier-passkey-name"><?php esc_html_e('Name (optional)', 'portier'); ?></label>
                                <input type="text" id="portier-passkey-name" class="regular-text" maxlength="60" autocomplete="off"
                                    aria-describedby="portier-passkey-name-hint">
                                <button type="button" class="button button-secondary" data-portier-passkey-create>
                                    <?php esc_html_e('Add a passkey', 'portier'); ?>
                                </button>
                                <p class="description" id="portier-passkey-name-hint">
                                    <?php esc_html_e('E.g. “iPhone” or “Work laptop”. Without a name, browser and system are used.', 'portier'); ?>
                                </p>
                            </div>
                        <?php endif; ?>
                        <?php if (! $this->passkeys->enabled()) : ?>
                            <p class="description">
                                <?php
                                echo PasskeyService::secureContext()
                                    ? esc_html__('Passkeys are switched off on this site. Existing passkeys cannot be used to log in.', 'portier')
                                    : esc_html__('Passkeys need a secure connection (HTTPS).', 'portier');
                                ?>
                            </p>
                        <?php endif; ?>
                        <noscript><p class="description"><?php esc_html_e('Managing passkeys needs JavaScript.', 'portier'); ?></p></noscript>
                        <p class="portier-passkeys__status" role="status" aria-live="polite" data-portier-passkey-status></p>
                    </div>
                </td>
            </tr>
        </table>
        <?php
    }
}
