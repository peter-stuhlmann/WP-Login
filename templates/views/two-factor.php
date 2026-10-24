<?php
/**
 * Second factor: code from the authenticator app.
 *
 * @var array<string, mixed>   $d
 * @var \Portier\Frontend\View $view
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$view->partial('heading', [
    'title' => __('Two-factor authentication', 'portier'),
    'description' => __('Enter the 6-digit code from your authenticator app.', 'portier'),
    'icon' => 'shield',
]);
$view->partial('error-summary', ['errors' => $d['errors']]);
?>
<form class="portier-form" method="post" action="<?php echo esc_url($d['urls']['self']); ?>" novalidate data-portier-form>
    <?php
    $view->partial('form-security', ['token' => $d['token']]);
    $view->partial('otp', [
        'name' => 'code',
        'label' => __('Verification code', 'portier'),
        'hint' => __('The code changes every 30 seconds.', 'portier'),
        'error' => $d['errors']['code'] ?? '',
    ]);
    if ((int) $d['trust_days'] > 0) {
        $view->partial('checkbox', [
            'name' => 'trust_device',
            /* translators: %d: number of days */
            'label' => sprintf(_n('Trust this device for %d day', 'Trust this device for %d days', (int) $d['trust_days'], 'portier'), (int) $d['trust_days']),
        ]);
    }
    $view->partial('submit', ['label' => __('Verify', 'portier'), 'loading' => __('Verifying…', 'portier')]);
    ?>
</form>

<?php if (! empty($d['passkey_url'])) : ?>
    <?php /* Figma "Passkey nutzen": a passkey replaces the code. Shown once the browser supports it. */ ?>
    <p class="portier-alt-method" data-portier-passkey hidden>
        <?php \Portier\Frontend\Icons::render('fingerprint', 18); ?>
        <a class="portier-link" href="<?php echo esc_url($d['passkey_url']); ?>" data-portier-nav><?php esc_html_e('Use a passkey instead', 'portier'); ?></a>
    </p>
<?php endif; ?>

<p class="portier-switch">
    <?php esc_html_e('No access to your app?', 'portier'); ?>
    <a class="portier-link" href="<?php echo esc_url($d['urls']['recovery']); ?>" data-portier-nav><?php esc_html_e('Use a recovery code', 'portier'); ?></a>
</p>
