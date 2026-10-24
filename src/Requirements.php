<?php

declare(strict_types=1);

namespace Portier;

/**
 * Checks the minimum PHP and WordPress versions before anything else is loaded.
 */
final class Requirements
{
    public const MIN_PHP = '8.1';
    public const MIN_WP = '6.5';

    public function __construct(
        private readonly string $phpVersion = PHP_VERSION,
        private readonly ?string $wpVersion = null,
    ) {
    }

    public function met(): bool
    {
        return version_compare($this->phpVersion, self::MIN_PHP, '>=')
            && version_compare($this->wordpressVersion(), self::MIN_WP, '>=');
    }

    /**
     * Shows a dismissible-free admin notice explaining why Portier is inactive.
     */
    public function showAdminNotice(): void
    {
        add_action(
            'admin_notices',
            function (): void {
                printf(
                    '<div class="notice notice-error"><p>%s</p></div>',
                    esc_html(
                        sprintf(
                            /* translators: 1: required PHP version, 2: required WordPress version */
                            __('Portier needs PHP %1$s and WordPress %2$s or newer. The custom login is inactive; the default login keeps working.', 'portier'),
                            self::MIN_PHP,
                            self::MIN_WP
                        )
                    )
                );
            }
        );
    }

    private function wordpressVersion(): string
    {
        if ($this->wpVersion !== null) {
            return $this->wpVersion;
        }
        return (string) get_bloginfo('version');
    }
}
