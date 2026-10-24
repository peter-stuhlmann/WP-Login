<?php

declare(strict_types=1);

namespace Portier\Frontend;

/**
 * Minimal PHP template renderer.
 *
 * Templates receive `$d` (their data) and `$view` (for partials). They are responsible for
 * escaping every value at output time – the WordPress way.
 */
final class View
{
    public function __construct(private readonly string $directory)
    {
    }

    /**
     * @param array<string, mixed> $d
     */
    public function render(string $template, array $d = []): string
    {
        $file = $this->directory . '/' . $template . '.php';
        if (! is_readable($file)) {
            throw new \InvalidArgumentException(esc_html(sprintf('Template "%s" not found.', esc_html($template))));
        }
        $view = $this;
        ob_start();
        (static function () use ($file, $d, $view): void {
            include $file;
        })();
        return (string) ob_get_clean();
    }

    /**
     * Renders and echoes a partial (used inside templates).
     *
     * @param array<string, mixed>|null $d Null renders the partial with no data (e.g. "no notice").
     */
    public function partial(string $template, ?array $d = []): void
    {
        echo $this->render('partials/' . $template, $d ?? []); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside the partial.
    }
}
