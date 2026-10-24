<?php
/**
 * Page layout of every Portier screen.
 *
 * The page is rendered without the theme: themes cannot break it, and it stays fast. Core's
 * login hooks (login_enqueue_scripts, login_head, login_footer) still run, so plugins that add
 * captchas or SSO buttons to the login page keep working.
 *
 * @var array<string, mixed>   $d
 * @var \Portier\Frontend\View $view
 */

declare(strict_types=1);

use Portier\Frontend\Icons;

defined('ABSPATH') || exit;

/** @var \Portier\Design\Design $portier_design */
$portier_design = $d['design'];
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?> class="no-js" data-portier-template="<?php echo esc_attr($portier_design->template); ?>" data-portier-scheme="<?php echo esc_attr($portier_design->scheme); ?>"<?php echo $d['preview'] ? ' data-portier-preview' : ''; ?><?php echo $portier_design->photo !== null ? ' data-portier-photo="' . ($portier_design->photo['onMobile'] ? 'all' : 'desktop') . '"' : ''; ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="<?php echo esc_attr($portier_design->colorSchemeMeta()); ?>">
    <title><?php echo esc_html($d['document_title']); ?></title>
    <?php
    /** This action is documented in wp-login.php */
    do_action('login_enqueue_scripts'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, fired for compatibility.
    /** This action is documented in wp-login.php */
    do_action('login_head'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, fired for compatibility.
    ?>
</head>
<body class="portier portier-screen-<?php echo esc_attr($d['screen']); ?>">
    <a class="portier-skip-link" href="#portier-card"><?php esc_html_e('Skip to the form', 'portier'); ?></a>

    <?php if ($portier_design->photo !== null) : ?>
        <div class="portier-photo" aria-hidden="true"></div>
    <?php endif; ?>
    <div class="portier-backdrop" aria-hidden="true">
        <span class="portier-aurora portier-aurora--1"></span>
        <span class="portier-aurora portier-aurora--2"></span>
        <span class="portier-aurora portier-aurora--3"></span>
        <span class="portier-aurora portier-aurora--4"></span>
    </div>

    <div class="portier-page">
        <header class="portier-header">
            <?php // Figma: follow-up screens (e.g. the password step) show "Back" instead of the logo. ?>
            <a class="portier-back" href="<?php echo esc_url($d['back']); ?>" data-portier-nav data-portier-back<?php echo $d['back'] === '' ? ' hidden' : ''; ?>>
                <?php Icons::render('arrow-left', 18); ?>
                <span><?php esc_html_e('Back', 'portier'); ?></span>
            </a>
            <a class="portier-brand" href="<?php echo esc_url($d['site']['url']); ?>" data-portier-brand<?php echo $d['back'] !== '' ? ' hidden' : ''; ?>>
                <?php if ($d['site']['icon'] !== '') : ?>
                    <img class="portier-brand__logo" src="<?php echo esc_url($d['site']['icon']); ?>" alt="" width="32" height="32">
                <?php else : ?>
                    <span class="portier-brand__mark" aria-hidden="true"><?php echo esc_html(mb_strtoupper(mb_substr($d['site']['name'], 0, 1))); ?></span>
                <?php endif; ?>
                <span class="portier-brand__name"><?php echo esc_html($d['site']['name']); ?></span>
                <?php if ($portier_design->template === 'enterprise' && $portier_design->headline !== '') : ?>
                    <span class="portier-brand__label"><?php echo esc_html($portier_design->headline); ?></span>
                <?php endif; ?>
                <span class="screen-reader-text"><?php esc_html_e('(go to home page)', 'portier'); ?></span>
            </a>

            <?php $view->partial('language-switcher', ['languages' => $d['languages']]); ?>
        </header>

        <?php if ($portier_design->panel && $portier_design->headline !== '') : ?>
            <?php
            // Templates with a side panel (Pure, Studio, Journal). The last word can be styled
            // separately (Journal: italic accent).
            $portier_words = explode(' ', $portier_design->headline);
            $portier_last = (string) array_pop($portier_words);
            ?>
            <aside class="portier-panel" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: site name */ __('About %s', 'portier'), $d['site']['name'])); ?>">
                <div class="portier-panel__content">
                    <span class="portier-panel__rule" aria-hidden="true"></span>
                    <p class="portier-panel__headline">
                        <?php echo esc_html($portier_words === [] ? '' : implode(' ', $portier_words) . ' '); ?><em><?php echo esc_html($portier_last); ?></em>
                    </p>
                    <?php if ($portier_design->text !== '') : ?>
                        <p class="portier-panel__text"><?php echo esc_html($portier_design->text); ?></p>
                    <?php endif; ?>
                </div>
            </aside>
        <?php endif; ?>

        <main class="portier-main" id="portier-main">
            <div class="portier-card" id="portier-card" tabindex="-1">
                <?php echo $d['content']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered view, escaped inside. ?>
            </div>
        </main>

        <footer class="portier-footer">
            <p class="portier-footer__copy">&copy; <?php echo esc_html(wp_date('Y') . ' ' . $d['site']['name']); ?></p>
            <?php if ($d['legal'] !== []) : ?>
                <nav aria-label="<?php esc_attr_e('Legal information', 'portier'); ?>">
                    <ul class="portier-footer__links">
                        <?php foreach ($d['legal'] as $portier_link) : ?>
                            <li><a href="<?php echo esc_url($portier_link['url']); ?>"><?php echo esc_html($portier_link['label']); ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        </footer>
    </div>

    <div id="portier-live" class="screen-reader-text" aria-live="polite" aria-atomic="true"></div>
    <?php
    /** This action is documented in wp-login.php */
    do_action('login_footer'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, fired for compatibility.
    ?>
</body>
</html>
