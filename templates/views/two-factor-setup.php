<?php
/**
 * Set up an authenticator app: scan the QR code (or type the key), confirm with a first code.
 *
 * @var array<string, mixed>   $d
 * @var \Portier\Frontend\View $view
 */

declare(strict_types=1);

use Portier\Frontend\Icons;

defined('ABSPATH') || exit;

$view->partial('heading', [
    'title' => __('Set up two-factor authentication', 'portier'),
    'description' => $d['required']
        ? __('Your account needs a second factor. Scan the QR code with an authenticator app such as 1Password, Aegis or Google Authenticator.', 'portier')
        : __('Scan the QR code with an authenticator app such as 1Password, Aegis or Google Authenticator.', 'portier'),
]);
$view->partial('error-summary', ['errors' => $d['errors']]);
?>
<p class="portier-steps" aria-hidden="true"><span class="is-done"></span><span></span></p>
<p class="portier-overline"><?php esc_html_e('Step 1 of 2', 'portier'); ?></p>

<div class="portier-qr-card">
    <?php /* Filled with an SVG QR code by the script; the key below is the text alternative. */ ?>
    <div class="portier-qr" data-portier-qr="<?php echo esc_attr($d['otpauth']); ?>" role="img"
        aria-label="<?php esc_attr_e('QR code for your authenticator app. The same key is shown as text next to it.', 'portier'); ?>" hidden></div>
    <div class="portier-qr-card__key">
        <p class="portier-hint" id="portier-secret-label"><?php esc_html_e('Can’t scan? Enter this key manually:', 'portier'); ?></p>
        <p class="portier-secret"><?php echo esc_html($d['secret_grouped']); ?></p>
        <button type="button" class="portier-chip-button" data-portier-copy="<?php echo esc_attr($d['secret']); ?>"
            data-copied="<?php esc_attr_e('Key copied.', 'portier'); ?>" hidden>
            <?php Icons::render('copy', 16); ?>
            <?php esc_html_e('Copy key', 'portier'); ?>
        </button>
    </div>
</div>

<form class="portier-form" method="post" action="<?php echo esc_url($d['urls']['self']); ?>" novalidate data-portier-form>
    <?php
    $view->partial('form-security', ['token' => $d['token']]);
    $view->partial('otp', [
        'name' => 'code',
        'label' => __('Code from the app', 'portier'),
        'hint' => __('Enter the 6-digit code the app shows after adding the account.', 'portier'),
        'error' => $d['errors']['code'] ?? '',
    ]);
    $view->partial('submit', ['label' => __('Activate', 'portier'), 'loading' => __('Activating…', 'portier')]);
    ?>
</form>
