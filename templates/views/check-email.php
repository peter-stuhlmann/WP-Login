<?php
/**
 * Confirmation after requesting a reset link, a login link or registering. The wording is
 * deliberately the same whether or not an account exists for the address.
 *
 * @var array<string, mixed>   $d
 * @var \Portier\Frontend\View $view
 */

declare(strict_types=1);

use Portier\Frontend\Icons;

defined('ABSPATH') || exit;

$portier_descriptions = [
    'registered' => __('We have sent you an email with a link to set your password and finish creating your account.', 'portier'),
    'magic' => __('If an account exists for the details you entered, we have sent an email with a login link.', 'portier'),
    'confirm' => __('If an account exists for the details you entered, we have sent an email with a link to set a new password.', 'portier'),
];
$portier_validity = $d['variant'] === 'magic'
    ? sprintf(
        /* translators: %d: lifetime of the login link in minutes */
        _n('The link is valid for %d minute and works once.', 'The link is valid for %d minutes and works once.', (int) $d['minutes'], 'portier'),
        (int) $d['minutes']
    )
    : __('The link is valid for 24 hours.', 'portier');

$view->partial('heading', [
    'title' => __('Check your inbox', 'portier'),
    'description' => $portier_descriptions[$d['variant']] ?? $portier_descriptions['confirm'],
    'icon' => 'mail-check',
]);
?>
<div class="portier-alert portier-alert--info" role="status">
    <?php Icons::render('info', 20, 'portier-alert__icon'); ?>
    <p class="portier-alert__text">
        <?php echo esc_html($portier_validity . ' ' . __('No email? Please check your spam folder or try again in a few minutes.', 'portier')); ?>
    </p>
</div>

<p class="portier-switch">
    <a class="portier-link portier-link--back" href="<?php echo esc_url($d['urls']['login']); ?>" data-portier-nav>
        <?php Icons::render('arrow-left', 16); ?>
        <?php esc_html_e('Back to login', 'portier'); ?>
    </a>
</p>
