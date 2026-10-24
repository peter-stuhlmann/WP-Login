<?php
/**
 * Two-step login, step 2: password (Figma "Passwort").
 *
 * The account chip shows what was entered in step 1, with "Change" back to step 1. A hidden
 * username field lets password managers match the saved login to this password form.
 *
 * @var array<string, mixed>   $d
 * @var \Portier\Frontend\View $view
 */

declare(strict_types=1);

use Portier\Frontend\Icons;

defined('ABSPATH') || exit;

$portier_errors = $d['errors'];
$portier_login = (string) $d['login'];
$portier_initial = mb_strtoupper(mb_substr($portier_login, 0, 1));
?>
<?php
$view->partial('heading', [
    'title' => $d['title'],
    'description' => $portier_errors === [] ? __('Nice to have you back.', 'portier') : '',
]);
?>
<?php $view->partial('error-summary', ['errors' => $portier_errors, 'title' => __('Login failed', 'portier')]); ?>

<div class="portier-account">
    <span class="portier-account__avatar" aria-hidden="true"><?php echo esc_html($portier_initial); ?></span>
    <span class="portier-account__login">
        <span class="screen-reader-text"><?php esc_html_e('Logging in as', 'portier'); ?></span>
        <?php echo esc_html($portier_login); ?>
    </span>
    <a class="portier-account__change" href="<?php echo esc_url($d['change_url']); ?>" data-portier-nav>
        <?php esc_html_e('Change', 'portier'); ?>
        <span class="screen-reader-text"><?php esc_html_e('email address or username', 'portier'); ?></span>
    </a>
</div>

<form class="portier-form" method="post" action="<?php echo esc_url($d['urls']['self']); ?>" novalidate data-portier-form>
    <?php $view->partial('form-security', ['token' => $d['token']]); ?>
    <input type="hidden" name="portier_step" value="password">
    <?php if ($d['redirect_to'] !== '') : ?>
        <input type="hidden" name="redirect_to" value="<?php echo esc_attr($d['redirect_to']); ?>">
    <?php endif; ?>
    <input type="text" name="log" value="<?php echo esc_attr($portier_login); ?>" autocomplete="username" hidden>
    <?php
    $view->partial('field', [
        'name' => 'pwd',
        'label' => __('Password', 'portier'),
        'type' => 'password',
        'autocomplete' => 'current-password',
        'required' => true,
        'autofocus' => true,
        'error' => $portier_errors['pwd'] ?? '',
        'invalid' => isset($portier_errors['_form']),
        'describedby' => isset($portier_errors['_form']) ? 'portier-errors-text' : '',
        'aside' => ['url' => $d['urls']['lostpassword'], 'label' => __('Forgot password?', 'portier')],
    ]);
    $view->partial('checkbox', [
        'name' => 'rememberme',
        'label' => __('Keep me logged in', 'portier'),
        'checked' => ! empty($d['remember_default']),
    ]);

    /** This action is documented in wp-login.php – lets plugins add fields (e.g. captchas). */
    do_action('login_form'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, fired for compatibility.

    $view->partial('submit', ['label' => __('Log in', 'portier'), 'loading' => __('Logging in…', 'portier'), 'icon' => 'arrow-right']);
    ?>
</form>

<?php if ($d['magic_url'] !== '') : ?>
    <p class="portier-alt-method">
        <?php Icons::render('sparkles', 18); ?>
        <a class="portier-link" href="<?php echo esc_url($d['magic_url']); ?>" data-portier-nav>
            <?php esc_html_e('Send me a login link instead', 'portier'); ?>
        </a>
    </p>
<?php endif; ?>
