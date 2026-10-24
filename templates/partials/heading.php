<?php
/**
 * Screen heading. The h1 is focusable so the script can move focus to it after a screen change
 * (screen readers then announce the new screen).
 *
 * @var array{title: string, description?: string, icon?: string, tone?: string} $d
 */

declare(strict_types=1);

use Portier\Frontend\Icons;

defined('ABSPATH') || exit;
?>
<div class="portier-heading">
    <?php if (! empty($d['icon'])) : ?>
        <span class="portier-badge portier-badge--<?php echo esc_attr($d['tone'] ?? 'accent'); ?>">
            <?php Icons::render($d['icon'], 28); ?>
        </span>
    <?php endif; ?>
    <h1 class="portier-title" id="portier-title" tabindex="-1"><?php echo esc_html($d['title']); ?></h1>
    <?php if (! empty($d['description'])) : ?>
        <p class="portier-lead"><?php echo esc_html($d['description']); ?></p>
    <?php endif; ?>
</div>
