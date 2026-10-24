<?php
/**
 * Other login methods below the form (Figma "Weitere Methoden"): divider, then the passkey
 * button and the social providers in the order the admin chose.
 *
 * Social providers next to each other share a row: one provider is a full-width button with
 * text, several are equal icon buttons, each with a visually hidden name ("Continue with
 * Google") for screen readers and voice control. The passkey button is full width and hidden
 * until the script confirms the browser supports passkeys (data-portier-passkey).
 * The links carry "Keep me logged in" from the form (see enhance.js, data-portier-carry).
 *
 * @var array{methods: list<array{type: string, id: string, url: string, label?: string}>} $d
 */

declare(strict_types=1);

use Portier\Frontend\Icons;

defined('ABSPATH') || exit;

$portier_methods = $d['methods'];
if ($portier_methods === []) {
    return;
}

// Consecutive social providers form one group; the passkey button stands on its own.
$portier_groups = [];
foreach ($portier_methods as $portier_method) {
    $portier_last = count($portier_groups) - 1;
    if ($portier_method['type'] === 'social' && $portier_last >= 0 && $portier_groups[$portier_last][0]['type'] === 'social') {
        $portier_groups[$portier_last][] = $portier_method;
    } else {
        $portier_groups[] = [$portier_method];
    }
}
// Only a passkey button (hidden without passkey support): the divider waits for it as well.
$portier_only_passkey = count($portier_methods) === 1 && $portier_methods[0]['type'] === 'passkey';
?>
<div class="portier-divider"<?php echo $portier_only_passkey ? ' data-portier-passkey hidden' : ''; ?>><span><?php esc_html_e('or', 'portier'); ?></span></div>
<div class="portier-methods">
    <?php foreach ($portier_groups as $portier_group) : ?>
        <?php if ($portier_group[0]['type'] === 'passkey') : ?>
            <a class="portier-button portier-button--secondary portier-passkey-button" href="<?php echo esc_url($portier_group[0]['url']); ?>"
                data-portier-nav data-portier-passkey data-portier-carry="portier-rememberme:rememberme" hidden>
                <?php Icons::render('fingerprint', 20); ?>
                <span class="portier-button__label"><?php esc_html_e('Log in with a passkey', 'portier'); ?></span>
            </a>
        <?php else : ?>
            <?php $portier_compact = count($portier_group) > 1; ?>
            <ul class="portier-social<?php echo $portier_compact ? ' portier-social--compact' : ''; ?>" role="list">
                <?php foreach ($portier_group as $portier_button) : ?>
                    <li>
                        <a class="portier-button portier-button--secondary portier-social__button" href="<?php echo esc_url($portier_button['url']); ?>"
                            data-portier-carry="portier-rememberme:rememberme" data-provider="<?php echo esc_attr($portier_button['id']); ?>">
                            <?php Icons::brand($portier_button['id'], 20); ?>
                            <?php
                            /* translators: %s: provider name, e.g. Google */
                            $portier_label = sprintf(__('Continue with %s', 'portier'), (string) ($portier_button['label'] ?? ''));
                            ?>
                            <span class="<?php echo $portier_compact ? 'screen-reader-text' : 'portier-button__label'; ?>"><?php echo esc_html($portier_label); ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
