<?php
/**
 * Registration is disabled.
 *
 * @var array<string, mixed>   $d
 * @var \Portier\Frontend\View $view
 */

declare(strict_types=1);

use Portier\Frontend\Icons;

defined('ABSPATH') || exit;

$view->partial('heading', [
    'title' => __('Registration is closed', 'portier'),
    'description' => __('New accounts cannot be created at the moment. If you already have an account, you can log in.', 'portier'),
    'icon' => 'info',
]);
?>
<div class="portier-actions">
    <a class="portier-button portier-button--primary" href="<?php echo esc_url($d['urls']['login']); ?>" data-portier-nav>
        <span class="portier-button__label"><?php esc_html_e('Go to login', 'portier'); ?></span>
        <?php Icons::render('arrow-right', 18, 'portier-button__icon'); ?>
    </a>
</div>
