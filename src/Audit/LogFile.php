<?php

declare(strict_types=1);

namespace Portier\Audit;

/**
 * The audit log on disk: one file per month, appended line by line.
 *
 * Location: `wp-content/portier-logs/` or the directory in `PORTIER_LOG_DIR` (wp-config.php) –
 * best outside the web root. The directory is closed to browsers (.htaccess, web.config, empty
 * index.php), and the file names contain a random token, so servers that ignore .htaccess
 * (nginx) cannot serve them by a guessed name either. Files older than the retention period
 * (12 months, filter `portier_audit_log_retention_months`) are deleted once a day.
 */
final class LogFile
{
    /** Random part of the file names (network option). */
    public const TOKEN_OPTION = 'portier_log_token';
    private const PRUNE_TRANSIENT = 'portier_log_pruned';

    public function __construct(private readonly string $directory)
    {
    }

    public static function fromConfig(): self
    {
        $directory = defined('PORTIER_LOG_DIR') && is_string(PORTIER_LOG_DIR) && PORTIER_LOG_DIR !== ''
            ? PORTIER_LOG_DIR
            : WP_CONTENT_DIR . '/portier-logs';
        return new self(untrailingslashit(wp_normalize_path($directory)));
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * File of the month the timestamp falls in (site time zone).
     */
    public function path(int $timestamp): string
    {
        return $this->directory . '/portier-' . wp_date('Y-m', $timestamp) . '-' . $this->token() . '.log';
    }

    /**
     * Appends lines. Never throws: a full disk or missing rights must not break a login.
     *
     * @param list<string> $lines
     */
    public function append(array $lines, int $timestamp): bool
    {
        if ($lines === [] || ! $this->prepare()) {
            return false;
        }
        $path = $this->path($timestamp);
        $new = ! file_exists($path);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Appending with a lock; WP_Filesystem cannot append.
        $handle = @fopen($path, 'ab');
        if ($handle === false) {
            return false;
        }
        flock($handle, LOCK_EX);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- See above.
        $written = fwrite($handle, implode("\n", $lines) . "\n");
        flock($handle, LOCK_UN);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- See above.
        fclose($handle);
        if ($new) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Not readable for other users of the server.
            @chmod($path, 0640);
        }
        $this->prune();
        return $written !== false;
    }

    /**
     * Log files of this site, oldest first.
     *
     * @return list<string>
     */
    public function files(): array
    {
        $files = glob($this->directory . '/portier-*-' . $this->token() . '.log');
        if (! is_array($files)) {
            return [];
        }
        sort($files);
        return $files;
    }

    /**
     * Deletes all log files and the protection files (uninstall).
     */
    public function deleteAll(): void
    {
        $files = glob($this->directory . '/portier-*.log');
        foreach (array_merge(is_array($files) ? $files : [], [$this->directory . '/.htaccess', $this->directory . '/web.config', $this->directory . '/index.php']) as $file) {
            if (is_file($file)) {
                wp_delete_file($file);
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Only the now empty log directory.
        @rmdir($this->directory);
        delete_site_option(self::TOKEN_OPTION);
    }

    /**
     * Creates the directory with its protection files.
     */
    private function prepare(): bool
    {
        if (! is_dir($this->directory) && ! wp_mkdir_p($this->directory)) {
            return false;
        }
        $protection = [
            // Apache 2.4 and 2.2.
            '.htaccess' => implode("\n", [
                '# Portier audit log – not for browsers.',
                '<IfModule mod_authz_core.c>',
                '	Require all denied',
                '</IfModule>',
                '<IfModule !mod_authz_core.c>',
                '	Deny from all',
                '</IfModule>',
                '',
            ]),
            // IIS.
            'web.config' => implode("\n", [
                '<?xml version="1.0" encoding="UTF-8"?>',
                '<configuration>',
                '	<system.webServer>',
                '		<authorization>',
                '			<deny users="*" />',
                '		</authorization>',
                '	</system.webServer>',
                '</configuration>',
                '',
            ]),
            'index.php' => "<?php\n// Silence is golden.\n",
        ];
        foreach ($protection as $name => $content) {
            $file = $this->directory . '/' . $name;
            if (! file_exists($file)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Small static file, written once.
                @file_put_contents($file, $content);
            }
        }
        return wp_is_writable($this->directory);
    }

    /**
     * Removes files older than the retention period (at most once a day).
     */
    private function prune(): void
    {
        if (get_site_transient(self::PRUNE_TRANSIENT) !== false) {
            return;
        }
        set_site_transient(self::PRUNE_TRANSIENT, 1, DAY_IN_SECONDS);
        /**
         * Months the audit log is kept (default 12).
         *
         * @param int $months
         */
        $months = max(1, (int) apply_filters('portier_audit_log_retention_months', 12));
        $oldest = wp_date('Y-m', (int) strtotime('-' . ($months - 1) . ' months', time()));
        foreach ($this->files() as $file) {
            if (preg_match('/portier-(\d{4}-\d{2})-/', basename($file), $match) === 1 && $match[1] < $oldest) {
                wp_delete_file($file);
            }
        }
    }

    private function token(): string
    {
        $token = get_site_option(self::TOKEN_OPTION);
        if (! is_string($token) || preg_match('/^[a-f0-9]{16}$/', $token) !== 1) {
            $token = bin2hex(random_bytes(8));
            update_site_option(self::TOKEN_OPTION, $token);
        }
        return $token;
    }
}
