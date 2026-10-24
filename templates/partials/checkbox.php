<?php
/**
 * Accessible checkbox: a native input (transparent, 24 px target) under the Figma box
 * (22 px, template radius, accent fill with a white check mark); the whole label is clickable.
 *
 * `label_html` may contain a link; it is filtered with wp_kses() (only <a> and <span> survive).
 *
 * @var array{name: string, label?: string, label_html?: string, checked?: bool, error?: string, required?: bool} $d
 */

declare(strict_types=1);

use Portier\Frontend\Icons;

defined('ABSPATH') || exit;

$portier_id = 'portier-' . $d['name'];
$portier_error = $d['error'] ?? '';
?>
<div class="portier-check<?php echo $portier_error !== '' ? ' is-invalid' : ''; ?>">
    <span class="portier-check__box">
        <input
            class="portier-check__control"
            type="checkbox"
            id="<?php echo esc_attr($portier_id); ?>"
            name="<?php echo esc_attr($d['name']); ?>"
            value="1"
            <?php checked(! empty($d['checked'])); ?>
            <?php echo ! empty($d['required']) ? 'required' : ''; ?>
            <?php if ($portier_error !== '') : ?>
                aria-invalid="true" aria-describedby="<?php echo esc_attr($portier_id . '-error'); ?>"
            <?php endif; ?>
        >
        <?php // Figma box: drawn on top of the real (transparent) checkbox, which keeps its 24 px target. ?>
        <span class="portier-check__mark" aria-hidden="true"><?php Icons::render('check', 16, 'portier-check__icon'); ?></span>
    </span>
    <label class="portier-check__label" for="<?php echo esc_attr($portier_id); ?>">
        <?php
        if (isset($d['label_html'])) {
            echo wp_kses($d['label_html'], [
                'a' => ['class' => true, 'href' => true, 'target' => true, 'rel' => true],
                'span' => ['class' => true],
            ]);
        } else {
            echo esc_html($d['label'] ?? '');
        }
        ?>
    </label>
</div>
<?php if ($portier_error !== '') : ?>
    <p class="portier-error portier-error--check" id="<?php echo esc_attr($portier_id . '-error'); ?>">
        <?php Icons::render('alert', 16); ?>
        <span><?php echo esc_html($portier_error); ?></span>
    </p>
<?php endif; ?>
