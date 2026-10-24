<?php
/**
 * Language switcher in the header. Also sent with every in-place screen change (fragment
 * responses), so its links always lead to the screen that is shown.
 *
 * @var array{languages: list<array{locale: string, label: string, short: string, url: string, current: bool, lang: string}>} $d
 */

declare(strict_types=1);

use Portier\Frontend\Icons;

defined('ABSPATH') || exit;

$portier_current_language = null;
foreach ($d['languages'] as $portier_language) {
    if ($portier_language['current']) {
        $portier_current_language = $portier_language;
    }
}
?>
<?php if ($portier_current_language !== null) : ?>
    <details class="portier-lang">
        <summary class="portier-lang__toggle">
            <?php Icons::render('globe', 16); ?>
            <span aria-hidden="true"><?php echo esc_html($portier_current_language['short']); ?></span>
            <span class="screen-reader-text">
                <?php
                /* translators: %s: name of the current language */
                echo esc_html(sprintf(__('Language: %s. Change language', 'portier'), $portier_current_language['label']));
                ?>
            </span>
            <?php Icons::render('chevron-down', 14, 'portier-lang__chevron'); ?>
        </summary>
        <ul class="portier-lang__menu">
            <?php foreach ($d['languages'] as $portier_language) : ?>
                <li>
                    <a href="<?php echo esc_url($portier_language['url']); ?>"
                        lang="<?php echo esc_attr($portier_language['lang']); ?>"
                        hreflang="<?php echo esc_attr($portier_language['lang']); ?>"
                        <?php echo $portier_language['current'] ? 'aria-current="true"' : ''; ?>>
                        <?php echo esc_html($portier_language['label']); ?>
                        <?php if ($portier_language['current']) : ?>
                            <?php Icons::render('check', 16); ?>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </details>
<?php endif; ?>
