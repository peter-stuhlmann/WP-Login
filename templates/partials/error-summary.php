<?php
/**
 * Error summary at the top of a form (GOV.UK pattern): one place that lists every problem,
 * with links that jump to the affected field. The script moves focus here after a failed submit.
 *
 * @var array{errors: array<string, string>, title?: string, labels?: array<string, string>} $d
 */

declare(strict_types=1);

use Portier\Auth\AuthResult;
use Portier\Frontend\Icons;

defined('ABSPATH') || exit;

if (empty($d['errors'])) {
    return;
}
$portier_form_error = $d['errors'][AuthResult::FORM] ?? null;
$portier_field_errors = array_diff_key($d['errors'], [AuthResult::FORM => true]);
?>
<div class="portier-alert portier-alert--error portier-error-summary" id="portier-errors" role="alert" tabindex="-1" aria-labelledby="portier-errors-title">
    <?php Icons::render('alert', 20, 'portier-alert__icon'); ?>
    <div class="portier-alert__body">
        <p class="portier-alert__title" id="portier-errors-title">
            <?php echo esc_html($d['title'] ?? __('Please check your input', 'portier')); ?>
        </p>
        <?php if ($portier_form_error !== null) : ?>
            <p class="portier-alert__text" id="portier-errors-text"><?php echo esc_html($portier_form_error); ?></p>
        <?php endif; ?>
        <?php if ($portier_field_errors !== []) : ?>
            <ul class="portier-alert__list">
                <?php foreach ($portier_field_errors as $portier_field => $portier_message) : ?>
                    <li><a href="#portier-<?php echo esc_attr($portier_field); ?>"><?php echo esc_html($portier_message); ?></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
