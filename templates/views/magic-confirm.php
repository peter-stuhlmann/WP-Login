<?php
/**
 * Confirmation page opened by the magic link. The login happens only on the button press
 * (POST), so e-mail scanners that open links cannot log anybody in.
 *
 * @var array<string, mixed>   $d
 * @var \Portier\Frontend\View $view
 */

declare(strict_types=1);

use Portier\Frontend\Icons;

defined('ABSPATH') || exit;

/** @var \WP_User $portier_user */
$portier_user = $d['user'];
$portier_device = $d['device'];
$portier_device_label = trim(sprintf(
    /* translators: 1: browser name, 2: operating system */
    __('%1$s on %2$s', 'portier'),
    $portier_device['browser'] !== '' ? $portier_device['browser'] : __('Browser', 'portier'),
    $portier_device['os'] !== '' ? $portier_device['os'] : __('this device', 'portier')
));

$view->partial('heading', [
    'title' => __('Confirm your login', 'portier'),
    /* translators: %s: email address of the account */
    'description' => sprintf(__('You are about to log in as %s.', 'portier'), $portier_user->user_email),
    'icon' => 'sparkles',
]);
$view->partial('error-summary', ['errors' => $d['errors']]);
?>
<div class="portier-device">
    <span class="portier-device__icon"><?php Icons::render($portier_device['mobile'] ? 'smartphone' : 'monitor', 20); ?></span>
    <p class="portier-device__text">
        <strong><?php echo esc_html($portier_device_label); ?></strong>
        <span><?php esc_html_e('This device will be logged in.', 'portier'); ?></span>
    </p>
</div>

<form class="portier-form" method="post" action="<?php echo esc_url($d['urls']['self']); ?>" novalidate data-portier-form>
    <?php
    $view->partial('form-security', ['token' => $d['token']]);
    $view->partial('checkbox', ['name' => 'rememberme', 'label' => __('Keep me logged in', 'portier')]);
    $view->partial('submit', ['label' => __('Log in now', 'portier'), 'loading' => __('Logging in…', 'portier'), 'icon' => 'login']);
    ?>
</form>

<p class="portier-hint"><?php esc_html_e('Not you? Simply close this page – nothing happens without pressing the button.', 'portier'); ?></p>
