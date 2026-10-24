<?php
/**
 * One-time code input.
 *
 * Technically ONE input (inputmode="numeric", autocomplete="one-time-code"): pasting, SMS and
 * password-manager autofill and screen readers just work. The six boxes are drawn by the
 * script on top (aria-hidden); without JavaScript a large plain field is shown.
 *
 * @var array{name: string, label: string, error?: string, hint?: string} $d
 */

declare(strict_types=1);

use Portier\Frontend\Icons;

defined('ABSPATH') || exit;

$portier_id = 'portier-' . $d['name'];
$portier_error = $d['error'] ?? '';
$portier_described = array_filter([
    ! empty($d['hint']) ? $portier_id . '-hint' : '',
    $portier_error !== '' ? $portier_id . '-error' : '',
]);
?>
<div class="portier-field<?php echo $portier_error !== '' ? ' is-invalid' : ''; ?>">
    <label class="portier-label" for="<?php echo esc_attr($portier_id); ?>"><?php echo esc_html($d['label']); ?></label>
    <div class="portier-otp" data-portier-otp>
        <input
            class="portier-otp__input"
            id="<?php echo esc_attr($portier_id); ?>"
            name="<?php echo esc_attr($d['name']); ?>"
            type="text"
            inputmode="numeric"
            pattern="[0-9 ]*"
            maxlength="7"
            autocomplete="one-time-code"
            spellcheck="false"
            required
            <?php echo $portier_error !== '' ? 'aria-invalid="true"' : ''; ?>
            <?php if ($portier_described !== []) : ?>
                aria-describedby="<?php echo esc_attr(implode(' ', $portier_described)); ?>"
            <?php endif; ?>
        >
    </div>
    <?php if (! empty($d['hint'])) : ?>
        <p class="portier-hint" id="<?php echo esc_attr($portier_id . '-hint'); ?>"><?php echo esc_html($d['hint']); ?></p>
    <?php endif; ?>
    <?php if ($portier_error !== '') : ?>
        <p class="portier-error" id="<?php echo esc_attr($portier_id . '-error'); ?>">
            <?php Icons::render('alert', 16); ?>
            <span><?php echo esc_html($portier_error); ?></span>
        </p>
    <?php endif; ?>
</div>
