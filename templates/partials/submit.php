<?php
/**
 * Primary submit button. While submitting, the script keeps it focusable, sets aria-busy and
 * shows the loading label, so screen reader users know something is happening.
 *
 * @var array{label: string, loading: string, icon?: string} $d
 */

declare(strict_types=1);

use Portier\Frontend\Icons;

defined('ABSPATH') || exit;
?>
<button type="submit" class="portier-button portier-button--primary" data-portier-submit data-loading-label="<?php echo esc_attr($d['loading']); ?>">
    <?php Icons::render('loader', 18, 'portier-button__spinner'); ?>
    <span class="portier-button__label"><?php echo esc_html($d['label']); ?></span>
    <?php if (! empty($d['icon'])) : ?>
        <?php Icons::render($d['icon'], 18, 'portier-button__icon'); ?>
    <?php endif; ?>
</button>
