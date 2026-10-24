<?php
/**
 * Expired or already used reset link.
 *
 * @var array<string, mixed>   $d
 * @var \Portier\Frontend\View $view
 */

declare(strict_types=1);

use Portier\Frontend\Icons;

defined('ABSPATH') || exit;

$view->partial('heading', [
    'title' => __('This link is no longer valid', 'portier'),
    'description' => __('Password reset links work only once and expire after 24 hours. Simply request a new one.', 'portier'),
    'icon' => 'link-off',
    'tone' => 'warning',
]);
?>
<div class="portier-actions">
    <a class="portier-button portier-button--primary" href="<?php echo esc_url($d['urls']['lostpassword']); ?>" data-portier-nav>
        <span class="portier-button__label"><?php esc_html_e('Request a new link', 'portier'); ?></span>
        <?php Icons::render('arrow-right', 18, 'portier-button__icon'); ?>
    </a>
</div>
<p class="portier-switch">
    <a class="portier-link portier-link--back" href="<?php echo esc_url($d['urls']['login']); ?>" data-portier-nav>
        <?php Icons::render('arrow-left', 16); ?>
        <?php esc_html_e('Back to login', 'portier'); ?>
    </a>
</p>
