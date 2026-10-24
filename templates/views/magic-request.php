<?php
/**
 * Request a magic login link.
 *
 * @var array<string, mixed>   $d
 * @var \Portier\Frontend\View $view
 */

declare(strict_types=1);

use Portier\Frontend\Icons;

defined('ABSPATH') || exit;

$view->partial('heading', [
    'title' => __('Log in with a link', 'portier'),
    'description' => __('We will email you a link that logs you in – no password needed.', 'portier'),
    'icon' => 'sparkles',
]);
$view->partial('error-summary', ['errors' => $d['errors']]);
?>
<form class="portier-form" method="post" action="<?php echo esc_url($d['urls']['self']); ?>" novalidate data-portier-form>
    <?php $view->partial('form-security', ['token' => $d['token']]); ?>
    <?php if ($d['redirect_to'] !== '') : ?>
        <input type="hidden" name="redirect_to" value="<?php echo esc_attr($d['redirect_to']); ?>">
    <?php endif; ?>
    <?php
    $view->partial('field', [
        'name' => 'user_login',
        'label' => __('Email address or username', 'portier'),
        'value' => $d['values']['user_login'] ?? '',
        'autocomplete' => 'username',
        'icon' => 'mail',
        'required' => true,
        'error' => $d['errors']['user_login'] ?? '',
        'invalid' => isset($d['errors']['_form']),
    ]);
    $view->partial('submit', ['label' => __('Send login link', 'portier'), 'loading' => __('Sending…', 'portier')]);
    ?>
</form>

<p class="portier-switch">
    <a class="portier-link portier-link--back" href="<?php echo esc_url($d['urls']['login']); ?>" data-portier-nav>
        <?php Icons::render('arrow-left', 16); ?>
        <?php esc_html_e('Log in with password instead', 'portier'); ?>
    </a>
</p>
