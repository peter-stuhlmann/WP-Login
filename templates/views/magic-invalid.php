<?php
/**
 * Used or expired magic link.
 *
 * @var array<string, mixed>   $d
 * @var \Portier\Frontend\View $view
 */

declare(strict_types=1);

use Portier\Frontend\Icons;

defined('ABSPATH') || exit;

$view->partial('heading', [
    'title' => __('This login link is no longer valid', 'portier'),
    'description' => $d['expired']
        ? __('The link has expired. For your security, login links are only valid for a few minutes.', 'portier')
        : __('Login links work only once, and only the newest link is valid.', 'portier'),
    'icon' => 'link-off',
    'tone' => 'warning',
]);
?>
<div class="portier-actions">
    <?php if ($d['urls']['magiclink'] !== '') : ?>
        <a class="portier-button portier-button--primary" href="<?php echo esc_url($d['urls']['magiclink']); ?>" data-portier-nav>
            <span class="portier-button__label"><?php esc_html_e('Request a new link', 'portier'); ?></span>
            <?php Icons::render('arrow-right', 18, 'portier-button__icon'); ?>
        </a>
    <?php endif; ?>
    <a class="portier-button portier-button--secondary" href="<?php echo esc_url($d['urls']['login']); ?>" data-portier-nav>
        <span class="portier-button__label"><?php esc_html_e('Log in with password', 'portier'); ?></span>
    </a>
</div>
