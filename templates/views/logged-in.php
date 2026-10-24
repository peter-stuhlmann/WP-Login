<?php
/**
 * Shown when an already logged-in user opens the login page.
 *
 * @var array<string, mixed>   $d
 * @var \Portier\Frontend\View $view
 */

declare(strict_types=1);

use Portier\Frontend\Icons;

defined('ABSPATH') || exit;

/** @var \WP_User $portier_user */
$portier_user = $d['user'];
?>
<?php
$view->partial('heading', [
    'title' => __('You are already logged in', 'portier'),
    /* translators: %s: display name of the current user */
    'description' => sprintf(__('You are logged in as %s.', 'portier'), $portier_user->display_name),
    'icon' => 'check-circle',
    'tone' => 'success',
]);
?>
<div class="portier-actions">
    <a class="portier-button portier-button--primary" href="<?php echo esc_url($d['continue_url']); ?>">
        <span class="portier-button__label"><?php esc_html_e('Continue', 'portier'); ?></span>
        <?php Icons::render('arrow-right', 18, 'portier-button__icon'); ?>
    </a>
    <a class="portier-button portier-button--secondary" href="<?php echo esc_url($d['logout_url']); ?>">
        <?php Icons::render('logout', 18, 'portier-button__icon'); ?>
        <span class="portier-button__label"><?php esc_html_e('Log out', 'portier'); ?></span>
    </a>
</div>
