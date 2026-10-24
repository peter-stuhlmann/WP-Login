<?php
/**
 * Login screen.
 *
 * @var array<string, mixed>   $d
 * @var \Portier\Frontend\View $view
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$portier_errors = $d['errors'];
?>
<?php $view->partial('heading', ['title' => __('Welcome back', 'portier'), 'description' => sprintf(/* translators: %s: site name */ __('Log in to continue to %s.', 'portier'), get_bloginfo('name'))]); ?>

<?php $view->partial('notice', $d['notice']); ?>
<?php $view->partial('error-summary', ['errors' => $portier_errors, 'title' => __('Login failed', 'portier')]); ?>

<form class="portier-form" method="post" action="<?php echo esc_url($d['urls']['self']); ?>" novalidate data-portier-form>
    <?php $view->partial('form-security', ['token' => $d['token']]); ?>
    <?php if ($d['redirect_to'] !== '') : ?>
        <input type="hidden" name="redirect_to" value="<?php echo esc_attr($d['redirect_to']); ?>">
    <?php endif; ?>

    <?php
    $view->partial('field', [
        'name' => 'log',
        'label' => __('Email address or username', 'portier'),
        'value' => $d['values']['log'] ?? '',
        // "webauthn": the browser offers passkeys right in this field (autofill, Figma "Start").
        'autocomplete' => ! empty($d['passkey_autofill']) ? 'username webauthn' : 'username',
        'icon' => 'mail',
        'required' => true,
        'error' => $portier_errors['log'] ?? '',
        'invalid' => isset($portier_errors['_form']),
        'describedby' => isset($portier_errors['_form']) ? 'portier-errors-text' : '',
    ]);
    $view->partial('field', [
        'name' => 'pwd',
        'label' => __('Password', 'portier'),
        'type' => 'password',
        'autocomplete' => 'current-password',
        'required' => true,
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

<?php $view->partial('login-more', ['urls' => $d['urls'], 'methods' => $d['methods'] ?? []]); ?>
