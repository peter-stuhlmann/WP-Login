<?php

declare(strict_types=1);

namespace Portier\Http;

/**
 * Value object describing what to send. Only {@see Response::send()} has side effects.
 */
final class Response
{
    /** @var list<array{name: string, value: string, expires: int, path: string}> */
    private array $cookies = [];

    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly ?string $redirect = null,
        private array $headers = [],
        public readonly bool $external = false,
    ) {
    }

    /**
     * Redirect to another host (an identity provider). Only for URLs built by Portier itself
     * from a provider definition – never for user input.
     */
    public static function external(string $url): self
    {
        return new self(302, '', $url, [], true);
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body, null, ['Content-Type' => 'text/html; charset=' . get_option('blog_charset')]);
    }

    /**
     * 303 makes browsers follow with GET after a POST (Post/Redirect/Get).
     */
    public static function redirect(string $url, int $status = 303): self
    {
        return new self($status, '', $url);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function json(array $data, int $status = 200): self
    {
        return new self($status, (string) wp_json_encode($data), null, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    public function withCookie(string $name, string $value, int $expires, string $path): self
    {
        $clone = clone $this;
        $clone->cookies[] = ['name' => $name, 'value' => $value, 'expires' => $expires, 'path' => $path];
        return $clone;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * @return list<array{name: string, value: string, expires: int, path: string}>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function send(): void
    {
        status_header($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        foreach ($this->cookies as $cookie) {
            setcookie(
                $cookie['name'],
                $cookie['value'],
                [
                    'expires' => $cookie['expires'],
                    'path' => $cookie['path'],
                    'domain' => COOKIE_DOMAIN ?: '',
                    'secure' => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]
            );
        }
        if ($this->redirect !== null) {
            if ($this->external) {
                // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Provider URL built by Portier.
                wp_redirect($this->redirect, $this->status);
                return;
            }
            // URLs are validated by the controller; allow external hosts approved via allowed_redirect_hosts.
            wp_safe_redirect($this->redirect, $this->status);
            return;
        }
        echo $this->body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the templates.
    }
}
