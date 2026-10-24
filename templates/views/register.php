<?php
/**
 * Registration screen. Only the e-mail address is required; the password is set via the link
 * in the confirmation e-mail, which also proves that the address belongs to the visitor.
 *
 * @var array<string, mixed>   $d
 * @var \Portier\Frontend\View $view
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$portier_errors = $d['errors'];

$view->partial('heading', [
    'title' => __('Create your account', 'portier'),
    'description' => __('We will email you a link to set your password – that is all it takes.', 'portier'),
]);
$view->partial('error-summary', ['errors' => $portier_errors]);
?>
<form class="portier-form" method="post" action="<?php echo esc_url($d['urls']['self']); ?>" novalidate data-portier-form>
    <?php $view->partial('form-security', ['token' => $d['token']]); ?>
    <input type="hidden" name="portier_ts" value="<?php echo esc_attr($d['timestamp']); ?>">

    <?php /* Honeypot: invisible to people (and screen readers), irresistible for bots. */ ?>
    <div class="portier-hp" aria-hidden="true">
        <label for="portier-<?php echo esc_attr($d['honeypot']); ?>"><?php esc_html_e('Leave this field empty', 'portier'); ?></label>
        <input type="text" id="portier-<?php echo esc_attr($d['honeypot']); ?>" name="<?php echo esc_attr($d['honeypot']); ?>" value="" tabindex="-1" autocomplete="off">
    </div>

    <?php
    $view->partial('field', [
        'name' => 'user_email',
        'label' => __('Email address', 'portier'),
        'type' => 'email',
        'value' => $d['values']['email'] ?? '',
        'autocomplete' => 'email',
        'icon' => 'mail',
        'required' => true,
        'error' => $portier_errors['user_email'] ?? '',
    ]);
    $view->partial('field', [
        'name' => 'display_name',
        'label' => __('Your name', 'portier'),
        'value' => $d['values']['name'] ?? '',
        'autocomplete' => 'name',
        'icon' => 'user',
        'optional' => true,
        'error' => $portier_errors['display_name'] ?? '',
    ]);

    if ($d['consent_required']) {
        // One sentence with a placeholder, so every language can put the link where it belongs.
        $portier_link = sprintf(
            '<a class="portier-link" href="%1$s" target="_blank" rel="noopener">%2$s<span class="screen-reader-text"> %3$s</span></a>',
            esc_url($d['privacy_url']),
            esc_html__('privacy policy', 'portier'),
            esc_html__('(opens in a new tab)', 'portier')
        );
        $view->partial('checkbox', [
            'name' => 'privacy_consent',
            /* translators: %s: link to the privacy policy */
            'label_html' => sprintf(esc_html__('I agree to the processing of my data as described in the %s.', 'portier'), $portier_link),
            'required' => true,
            'error' => $portier_errors['privacy_consent'] ?? '',
        ]);
    }

    /** This action is documented in wp-login.php */
    do_action('register_form'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, fired for compatibility.

    $view->partial('submit', ['label' => __('Create account', 'portier'), 'loading' => __('Creating account…', 'portier')]);
    ?>
</form>

<p class="portier-switch">
    <?php esc_html_e('Already have an account?', 'portier'); ?>
    <a class="portier-link" href="<?php echo esc_url($d['urls']['login']); ?>" data-portier-nav><?php esc_html_e('Log in', 'portier'); ?></a>
</p>
