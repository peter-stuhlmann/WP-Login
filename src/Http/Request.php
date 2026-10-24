<?php

declare(strict_types=1);

namespace Portier\Http;

/**
 * Immutable snapshot of the current HTTP request (unslashed), so controllers can be tested
 * without touching superglobals.
 */
final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     * @param array<string, mixed> $cookies
     */
    public function __construct(
        public readonly string $method = 'GET',
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $server = [],
        public readonly array $cookies = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        // phpcs:disable WordPress.Security.NonceVerification -- Portier verifies its own form tokens.
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput -- Values are validated where they are used.
        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            wp_unslash($_GET),
            wp_unslash($_POST),
            $_SERVER,
            wp_unslash($_COOKIE)
        );
        // phpcs:enable
    }

    /**
     * Copy with additional query arguments (e.g. values the rewrite rules extracted from the path).
     *
     * @param array<string, scalar> $args
     */
    public function withQuery(array $args): self
    {
        return new self($this->method, $args + $this->query, $this->post, $this->server, $this->cookies);
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /**
     * Scalar query argument as string (arrays are ignored on purpose).
     */
    public function query(string $key, string $default = ''): string
    {
        return isset($this->query[$key]) && is_scalar($this->query[$key]) ? (string) $this->query[$key] : $default;
    }

    public function input(string $key, string $default = ''): string
    {
        return isset($this->post[$key]) && is_scalar($this->post[$key]) ? (string) $this->post[$key] : $default;
    }

    public function cookie(string $key): ?string
    {
        return isset($this->cookies[$key]) && is_scalar($this->cookies[$key]) ? (string) $this->cookies[$key] : null;
    }

    /**
     * True for requests from our progressive-enhancement script, which wants JSON with an
     * HTML fragment instead of a full page.
     */
    public function wantsFragment(): bool
    {
        return ($this->server['HTTP_X_PORTIER_REQUEST'] ?? '') === 'fragment';
    }
}
