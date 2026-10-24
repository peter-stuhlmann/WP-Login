<?php
/**
 * Passkey login (Figma "04 · Passkey · wartet"): pulse around the fingerprint, heading, a status
 * line that screen readers announce (aria-live), "Cancel" and "Choose another method".
 *
 * The script (passkeys.js) asks the device as soon as the screen is shown and reports progress
 * and problems in the status line; "Try again" appears when the attempt was cancelled or failed.
 * Without JavaScript passkeys cannot work – the screen says so and offers the other methods.
 *
 * @var array<string, mixed>   $d
 * @var \Portier\Frontend\View $view
 */

declare(strict_types=1);

use Portier\Frontend\Icons;

defined('ABSPATH') || exit;
?>
<div class="portier-passkey" data-portier-passkey-screen
    data-endpoint="<?php echo esc_url($d['urls']['self']); ?>"
    data-redirect-to="<?php echo esc_attr($d['redirect_to']); ?>"
    data-remember="<?php echo $d['remember'] ? '1' : ''; ?>"
    data-text-waiting="<?php esc_attr_e('Waiting for your device…', 'portier'); ?>"
    data-text-checking="<?php esc_attr_e('Checking…', 'portier'); ?>"
    data-text-success="<?php esc_attr_e('Logged in. One moment…', 'portier'); ?>"
    data-text-cancelled="<?php esc_attr_e('Cancelled. You can try again or choose another method.', 'portier'); ?>"
    data-text-unsupported="<?php esc_attr_e('This browser does not support passkeys. Please choose another method.', 'portier'); ?>"
    data-text-error="<?php esc_attr_e('The passkey could not be checked. Please try again or choose another method.', 'portier'); ?>">

    <div class="portier-passkey__pulse" aria-hidden="true">
        <span class="portier-passkey__ring"></span>
        <span class="portier-passkey__ring"></span>
        <span class="portier-passkey__ring"></span>
        <span class="portier-passkey__core"><?php Icons::render('fingerprint', 34); ?></span>
    </div>

    <?php
    $view->partial('heading', [
        'title' => __('Log in with a passkey', 'portier'),
        'description' => __('Confirm with Face ID, Touch ID, Windows Hello or your security key.', 'portier'),
    ]);
    ?>

    <p class="portier-passkey__status" role="status" aria-live="polite" data-portier-passkey-status>
        <?php Icons::render('loader', 18, 'portier-passkey__spinner'); ?>
        <span data-portier-passkey-text>
            <noscript><?php esc_html_e('Passkeys need JavaScript. Please enable it or choose another method.', 'portier'); ?></noscript>
        </span>
    </p>

    <div class="portier-passkey__actions">
        <button type="button" class="portier-button portier-button--primary" data-portier-passkey-retry hidden>
            <?php Icons::render('fingerprint', 18); ?>
            <span class="portier-button__label"><?php esc_html_e('Try again', 'portier'); ?></span>
        </button>
        <a class="portier-button portier-button--secondary" href="<?php echo esc_url($d['other_url']); ?>" data-portier-nav data-portier-passkey-cancel>
            <span class="portier-button__label"><?php esc_html_e('Cancel', 'portier'); ?></span>
        </a>
    </div>
</div>

<p class="portier-switch">
    <a class="portier-link" href="<?php echo esc_url($d['other_url']); ?>" data-portier-nav><?php esc_html_e('Choose another method', 'portier'); ?></a>
</p>
