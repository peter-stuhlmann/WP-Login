<?php
/**
 * Accessible text/password field.
 *
 * - The label is always visible; placeholders are never used as labels.
 * - Hint and error are connected via aria-describedby; errors also set aria-invalid.
 * - Password fields get a "show password" toggle (revealed by the script) and a Caps Lock hint.
 *
 * @var array{
 *     name: string, label: string, type?: string, value?: string, autocomplete?: string,
 *     required?: bool, error?: string, hint?: string, icon?: string, inputmode?: string,
 *     aside?: array{url: string, label: string}, optional?: bool, invalid?: bool, describedby?: string,
 *     autofocus?: bool
 * } $d
 */

declare(strict_types=1);

use Portier\Frontend\Icons;

defined('ABSPATH') || exit;

$portier_id = 'portier-' . $d['name'];
$portier_type = $d['type'] ?? 'text';
$portier_is_password = $portier_type === 'password';
$portier_error = $d['error'] ?? '';
// A field can be marked invalid without its own message, e.g. when the error summary explains it.
$portier_invalid = $portier_error !== '' || ! empty($d['invalid']);
$portier_described = empty($d['describedby']) ? [] : [$d['describedby']];
if (! empty($d['hint'])) {
    $portier_described[] = $portier_id . '-hint';
}
if ($portier_error !== '') {
    $portier_described[] = $portier_id . '-error';
}
?>
<div class="portier-field<?php echo $portier_invalid ? ' is-invalid' : ''; ?>">
    <div class="portier-field__head">
        <label class="portier-label" for="<?php echo esc_attr($portier_id); ?>">
            <?php echo esc_html($d['label']); ?>
            <?php if (! empty($d['optional'])) : ?>
                <span class="portier-optional"><?php esc_html_e('(optional)', 'portier'); ?></span>
            <?php endif; ?>
        </label>
        <?php if (! empty($d['aside'])) : ?>
            <a class="portier-link portier-field__aside" href="<?php echo esc_url($d['aside']['url']); ?>" data-portier-nav><?php echo esc_html($d['aside']['label']); ?></a>
        <?php endif; ?>
    </div>
    <div class="portier-input<?php echo ! empty($d['icon']) ? ' has-icon' : ''; ?>">
        <?php if (! empty($d['icon'])) : ?>
            <?php Icons::render($d['icon'], 20, 'portier-input__icon'); ?>
        <?php endif; ?>
        <input
            class="portier-input__control"
            id="<?php echo esc_attr($portier_id); ?>"
            name="<?php echo esc_attr($d['name']); ?>"
            type="<?php echo esc_attr($portier_type); ?>"
            value="<?php echo $portier_is_password ? '' : esc_attr($d['value'] ?? ''); ?>"
            <?php if (! empty($d['autocomplete'])) : ?>
                autocomplete="<?php echo esc_attr($d['autocomplete']); ?>"
            <?php endif; ?>
            <?php if (! empty($d['inputmode'])) : ?>
                inputmode="<?php echo esc_attr($d['inputmode']); ?>"
            <?php endif; ?>
            <?php if (in_array($portier_type, ['text', 'email'], true)) : ?>
                autocapitalize="none" spellcheck="false"
            <?php endif; ?>
            <?php echo ! empty($d['required']) ? 'required' : ''; ?>
            <?php echo ! empty($d['autofocus']) ? 'autofocus' : ''; ?>
            <?php echo $portier_invalid ? 'aria-invalid="true"' : ''; ?>
            <?php if ($portier_described !== []) : ?>
                aria-describedby="<?php echo esc_attr(implode(' ', $portier_described)); ?>"
            <?php endif; ?>
            <?php echo $portier_is_password ? 'data-portier-password' : ''; ?>
        >
        <?php if ($portier_is_password) : ?>
            <button
                type="button"
                class="portier-reveal"
                aria-controls="<?php echo esc_attr($portier_id); ?>"
                aria-pressed="false"
                data-portier-reveal
                data-label-show="<?php esc_attr_e('Show password', 'portier'); ?>"
                data-label-hide="<?php esc_attr_e('Hide password', 'portier'); ?>"
                hidden
            >
                <?php Icons::render('eye', 20, 'portier-reveal__show'); ?>
                <?php Icons::render('eye-off', 20, 'portier-reveal__hide'); ?>
                <span class="screen-reader-text"><?php esc_html_e('Show password', 'portier'); ?></span>
            </button>
        <?php endif; ?>
    </div>
    <?php if (! empty($d['hint'])) : ?>
        <p class="portier-hint" id="<?php echo esc_attr($portier_id . '-hint'); ?>"><?php echo esc_html($d['hint']); ?></p>
    <?php endif; ?>
    <?php if ($portier_is_password) : ?>
        <p class="portier-hint portier-caps" id="<?php echo esc_attr($portier_id . '-caps'); ?>" hidden>
            <?php Icons::render('info', 16); ?>
            <?php esc_html_e('Caps Lock is on.', 'portier'); ?>
        </p>
    <?php endif; ?>
    <?php if ($portier_error !== '') : ?>
        <p class="portier-error" id="<?php echo esc_attr($portier_id . '-error'); ?>">
            <?php Icons::render('alert', 16); ?>
            <span><?php echo esc_html($portier_error); ?></span>
        </p>
    <?php endif; ?>
</div>
