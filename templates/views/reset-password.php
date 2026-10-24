<?php
/**
 * "Set a new password" screen.
 *
 * @var array<string, mixed>   $d
 * @var \Portier\Frontend\View $view
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

/** @var \WP_User $portier_user */
$portier_user = $d['user'];
$portier_min = (int) $d['min_length'];

$view->partial('heading', [
    'title' => __('Set a new password', 'portier'),
    'description' => __('Choose a strong password that you do not use anywhere else.', 'portier'),
]);
$view->partial('error-summary', ['errors' => $d['errors']]);
?>
<form class="portier-form" method="post" action="<?php echo esc_url($d['urls']['self']); ?>" novalidate data-portier-form>
    <?php $view->partial('form-security', ['token' => $d['token']]); ?>

    <?php /* Lets password managers save the new password for the right account. */ ?>
    <input type="text" name="user_login" value="<?php echo esc_attr($portier_user->user_login); ?>" autocomplete="username" hidden>

    <?php
    $view->partial('field', [
        'name' => 'pass1',
        'label' => __('New password', 'portier'),
        'type' => 'password',
        'autocomplete' => 'new-password',
        'required' => true,
        /* translators: %d: minimum number of characters */
        'hint' => sprintf(_n('At least %d character. A short sentence is easy to remember and hard to guess.', 'At least %d characters. A short sentence is easy to remember and hard to guess.', $portier_min, 'portier'), $portier_min),
        'error' => $d['errors']['pass1'] ?? '',
    ]);
    $view->partial('field', [
        'name' => 'pass2',
        'label' => __('Repeat new password', 'portier'),
        'type' => 'password',
        'autocomplete' => 'new-password',
        'required' => true,
        'error' => $d['errors']['pass2'] ?? '',
    ]);

    /** This action is documented in wp-login.php */
    do_action('resetpass_form', $portier_user); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, fired for compatibility.

    $view->partial('submit', ['label' => __('Save password', 'portier'), 'loading' => __('Saving…', 'portier')]);
    ?>
</form>
