<?php
/**
 * Recovery codes, shown exactly once.
 *
 * @var array<string, mixed>   $d
 * @var \Portier\Frontend\View $view
 */

declare(strict_types=1);

use Portier\Frontend\Icons;

defined('ABSPATH') || exit;

/** @var list<string> $portier_codes */
$portier_codes = $d['codes'];
$view->partial('heading', [
    'title' => __('Save your recovery codes', 'portier'),
    'description' => __('With these codes you can log in even without your phone. Each code works once. Keep them in a safe place, e.g. your password manager.', 'portier'),
    'icon' => 'shield',
    'tone' => 'success',
]);
$view->partial('error-summary', ['errors' => $d['errors']]);
?>
<p class="portier-steps" aria-hidden="true"><span class="is-done"></span><span class="is-done"></span></p>
<p class="portier-overline"><?php esc_html_e('Step 2 of 2', 'portier'); ?></p>

<ul class="portier-codes" aria-label="<?php esc_attr_e('Recovery codes', 'portier'); ?>">
    <?php foreach ($portier_codes as $portier_code) : ?>
        <li><?php echo esc_html($portier_code); ?></li>
    <?php endforeach; ?>
</ul>

<div class="portier-code-actions" data-portier-codes="<?php echo esc_attr(implode("\n", $portier_codes)); ?>"
    data-filename="<?php echo esc_attr(sanitize_file_name(get_bloginfo('name') . '-recovery-codes.txt')); ?>" hidden>
    <button type="button" class="portier-button portier-button--secondary" data-portier-codes-action="copy" data-done="<?php esc_attr_e('Codes copied.', 'portier'); ?>">
        <?php Icons::render('copy', 18); ?><span><?php esc_html_e('Copy', 'portier'); ?></span>
    </button>
    <button type="button" class="portier-button portier-button--secondary" data-portier-codes-action="download">
        <?php Icons::render('download', 18); ?><span><?php esc_html_e('Download', 'portier'); ?></span>
    </button>
    <button type="button" class="portier-button portier-button--secondary" data-portier-codes-action="print">
        <?php Icons::render('printer', 18); ?><span><?php esc_html_e('Print', 'portier'); ?></span>
    </button>
</div>

<form class="portier-form" method="post" action="<?php echo esc_url($d['urls']['self']); ?>" novalidate data-portier-form>
    <?php
    $view->partial('form-security', ['token' => $d['token']]);
    $view->partial('checkbox', [
        'name' => 'codes_saved',
        'label' => __('I have saved my recovery codes in a safe place.', 'portier'),
        'required' => true,
        'error' => $d['errors']['codes_saved'] ?? '',
    ]);
    $view->partial('submit', [
        'label' => $d['from_login'] ? __('Continue to my account', 'portier') : __('Done', 'portier'),
        'loading' => __('One moment…', 'portier'),
    ]);
    ?>
</form>
