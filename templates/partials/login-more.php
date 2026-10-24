<?php
/**
 * Below the login form: magic link, social login and registration.
 *
 * @var array{urls: array<string, string>, methods: list<array{type: string, id: string, url: string, label?: string}>} $d
 * @var \Portier\Frontend\View $view
 */

declare(strict_types=1);

use Portier\Frontend\Icons;

defined('ABSPATH') || exit;
?>
<?php if ($d['urls']['magiclink'] !== '') : ?>
    <p class="portier-alt-method">
        <?php Icons::render('sparkles', 18); ?>
        <a class="portier-link" href="<?php echo esc_url($d['urls']['magiclink']); ?>" data-portier-nav data-portier-carry="portier-log:user_login">
            <?php esc_html_e('Send me a login link instead', 'portier'); ?>
        </a>
    </p>
<?php endif; ?>

<?php $view->partial('social', ['methods' => $d['methods']]); ?>

<?php if ($d['urls']['register'] !== '') : ?>
    <p class="portier-switch">
        <?php esc_html_e('New here?', 'portier'); ?>
        <a class="portier-link" href="<?php echo esc_url($d['urls']['register']); ?>" data-portier-nav><?php esc_html_e('Create an account', 'portier'); ?></a>
    </p>
<?php endif; ?>
