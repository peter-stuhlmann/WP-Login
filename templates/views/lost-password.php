<?php
/**
 * "Forgot password" screen.
 *
 * @var array<string, mixed>   $d
 * @var \Portier\Frontend\View $view
 */

declare(strict_types=1);

use Portier\Frontend\Icons;

defined('ABSPATH') || exit;
?>
<?php
$view->partial('heading', [
    'title' => __('Forgot your password?', 'portier'),
    'description' => __('No problem. Enter your email address or username and we will send you a link to set a new password.', 'portier'),
]);
$view->partial('notice', $d['notice']);
$view->partial('error-summary', ['errors' => $d['errors']]);
?>
<form class="portier-form" method="post" action="<?php echo esc_url($d['urls']['self']); ?>" novalidate data-portier-form>
    <?php
    $view->partial('form-security', ['token' => $d['token']]);
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

    /** This action is documented in wp-login.php */
    do_action('lostpassword_form'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, fired for compatibility.

    $view->partial('submit', ['label' => __('Send reset link', 'portier'), 'loading' => __('Sending…', 'portier')]);
    ?>
</form>

<p class="portier-switch">
    <a class="portier-link portier-link--back" href="<?php echo esc_url($d['urls']['login']); ?>" data-portier-nav>
        <?php Icons::render('arrow-left', 16); ?>
        <?php esc_html_e('Back to login', 'portier'); ?>
    </a>
</p>
