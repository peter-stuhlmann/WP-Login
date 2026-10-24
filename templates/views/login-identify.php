<?php
/**
 * Two-step login, step 1: e-mail address or username (Figma "Start").
 *
 * Posting this form never reveals whether the account exists – step 2 looks the same for all.
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
    <input type="hidden" name="portier_step" value="identify">
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
    $view->partial('submit', ['label' => __('Continue', 'portier'), 'loading' => __('One moment…', 'portier'), 'icon' => 'arrow-right']);
    ?>
</form>

<?php $view->partial('login-more', ['urls' => $d['urls'], 'methods' => $d['methods'] ?? []]); ?>
