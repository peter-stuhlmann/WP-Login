<?php

declare(strict_types=1);

namespace Portier\Auth;

/**
 * Outcome of an authentication-related action.
 *
 * Errors are keyed by form field name so the view can attach them to the right input;
 * the key {@see AuthResult::FORM} holds errors that concern the whole form.
 */
final class AuthResult
{
    public const FORM = '_form';

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed>  $data
     */
    private function __construct(
        public readonly bool $success,
        public readonly array $errors = [],
        public readonly ?string $redirect = null,
        public readonly array $data = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function success(?string $redirect = null, array $data = []): self
    {
        return new self(true, [], $redirect, $data);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed>  $data
     */
    public static function failure(array $errors, array $data = []): self
    {
        return new self(false, $errors, null, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function formError(string $message, array $data = []): self
    {
        return self::failure([self::FORM => $message], $data);
    }
}
