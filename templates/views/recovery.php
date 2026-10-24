<?php
/**
 * Second factor: one of the recovery codes.
 *
 * @var array<string, mixed>   $d
 * @var \Portier\Frontend\View $view
 */

declare(strict_types=1);

use Portier\Frontend\Icons;

defined('ABSPATH') || exit;

$view->partial('heading', [
    'title' => __('Use a recovery code', 'portier'),
    'description' => __('No access to your authenticator app? Enter one of the recovery codes you saved when setting up two-factor authentication.', 'portier'),
]);
$view->partial('error-summary', ['errors' => $d['errors']]);
?>
<form class="portier-form" method="post" action="<?php echo esc_url($d['urls']['self']); ?>" novalidate data-portier-form>
    <?php
    $view->partial('form-security', ['token' => $d['token']]);
    $view->partial('field', [
        'name' => 'recovery_code',
        'label' => __('Recovery code', 'portier'),
        'value' => $d['values']['recovery_code'] ?? '',
        'autocomplete' => 'off',
        'icon' => 'key',
        'required' => true,
        'hint' => __('Each code works only once. Hyphens are optional.', 'portier'),
        'error' => $d['errors']['recovery_code'] ?? '',
    ]);
    $view->partial('submit', ['label' => __('Log in', 'portier'), 'loading' => __('Logging in…', 'portier')]);
    ?>
</form>

<p class="portier-switch">
    <a class="portier-link portier-link--back" href="<?php echo esc_url($d['urls']['twofactor']); ?>" data-portier-nav>
        <?php Icons::render('arrow-left', 16); ?>
        <?php esc_html_e('Back to the authenticator code', 'portier'); ?>
    </a>
</p>
