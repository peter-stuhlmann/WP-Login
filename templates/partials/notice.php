<?php
/**
 * Status message (success / info). Errors use the error summary instead.
 *
 * @var array{type?: string, text?: string}|null $d
 */

declare(strict_types=1);

use Portier\Frontend\Icons;

defined('ABSPATH') || exit;

if (empty($d['text'])) {
    return;
}
$portier_type = in_array($d['type'] ?? '', ['success', 'info', 'error'], true) ? $d['type'] : 'info';
$portier_icon = ['success' => 'check-circle', 'info' => 'info', 'error' => 'alert'][$portier_type];
?>
<div class="portier-alert portier-alert--<?php echo esc_attr($portier_type); ?>" role="<?php echo $portier_type === 'error' ? 'alert' : 'status'; ?>">
    <?php Icons::render($portier_icon, 20, 'portier-alert__icon'); ?>
    <p class="portier-alert__text"><?php echo esc_html($d['text']); ?></p>
</div>
