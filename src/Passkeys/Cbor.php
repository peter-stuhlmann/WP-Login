<?php

declare(strict_types=1);

namespace Portier\Passkeys;

/**
 * Minimal CBOR decoder (RFC 8949) for the data authenticators send: attestation objects and
 * COSE public keys.
 *
 * Supports exactly what WebAuthn uses – unsigned and negative integers, byte and text strings,
 * arrays, maps, true/false/null – with definite lengths only (CTAP2 canonical CBOR). Everything
 * else (indefinite lengths, tags, floats) is rejected. Lengths are checked against the remaining
 * input and nesting is limited, so hostile input cannot make the decoder allocate or recurse
 * without bound. Byte and text strings both become PHP strings.
 */
final class Cbor
{
    private const MAX_DEPTH = 16;

    private int $offset = 0;

    private function __construct(private readonly string $data)
    {
    }

    /**
     * Decodes one complete CBOR item; trailing bytes are an error.
     *
     * @throws PasskeyException
     */
    public static function decode(string $data): mixed
    {
        [$value, $length] = self::decodePrefix($data);
        if ($length !== strlen($data)) {
            throw new PasskeyException(esc_html(PasskeyException::MALFORMED), esc_html('Trailing bytes after CBOR item.'));
        }
        return $value;
    }

    /**
     * Decodes the first CBOR item and reports how many bytes it used (the credential public
     * key in authenticator data may be followed by extensions).
     *
     * @return array{0: mixed, 1: int} Value and length in bytes.
     * @throws PasskeyException
     */
    public static function decodePrefix(string $data): array
    {
        $decoder = new self($data);
        $value = $decoder->item(0);
        return [$value, $decoder->offset];
    }

    /**
     * @throws PasskeyException
     */
    private function item(int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new PasskeyException(esc_html(PasskeyException::MALFORMED), esc_html('CBOR nesting too deep.'));
        }
        $initial = ord($this->read(1));
        $major = $initial >> 5;
        $info = $initial & 0x1f;

        if ($major === 7) {
            return match ($info) {
                20 => false,
                21 => true,
                22 => null,
                default => throw new PasskeyException(esc_html(PasskeyException::MALFORMED), esc_html('Unsupported CBOR simple value.')),
            };
        }

        $argument = $this->argument($info);
        switch ($major) {
            case 0:
                return $argument;
            case 1:
                return -1 - $argument;
            case 2:
            case 3:
                return $this->read($argument);
            case 4:
                $items = [];
                for ($i = 0; $i < $argument; $i++) {
                    $items[] = $this->item($depth + 1);
                }
                return $items;
            case 5:
                $map = [];
                for ($i = 0; $i < $argument; $i++) {
                    $key = $this->item($depth + 1);
                    if (! is_int($key) && ! is_string($key)) {
                        throw new PasskeyException(esc_html(PasskeyException::MALFORMED), esc_html('Unsupported CBOR map key.'));
                    }
                    if (array_key_exists($key, $map)) {
                        throw new PasskeyException(esc_html(PasskeyException::MALFORMED), esc_html('Duplicate CBOR map key.'));
                    }
                    $map[$key] = $this->item($depth + 1);
                }
                return $map;
            default:
                throw new PasskeyException(esc_html(PasskeyException::MALFORMED), esc_html('CBOR tags are not supported.'));
        }
    }

    /**
     * Length or value that follows the initial byte.
     *
     * @throws PasskeyException
     */
    private function argument(int $info): int
    {
        if ($info < 24) {
            return $info;
        }
        $bytes = match ($info) {
            24 => 1,
            25 => 2,
            26 => 4,
            27 => 8,
            default => throw new PasskeyException(esc_html(PasskeyException::MALFORMED), esc_html('Indefinite CBOR lengths are not supported.')),
        };
        $value = 0;
        foreach (str_split($this->read($bytes)) as $byte) {
            if ($value > (PHP_INT_MAX >> 8)) {
                throw new PasskeyException(esc_html(PasskeyException::MALFORMED), esc_html('CBOR integer too large.'));
            }
            $value = ($value << 8) | ord($byte);
        }
        return $value;
    }

    /**
     * @throws PasskeyException
     */
    private function read(int $length): string
    {
        if ($length < 0 || $length > strlen($this->data) - $this->offset) {
            throw new PasskeyException(esc_html(PasskeyException::MALFORMED), esc_html('CBOR data ends early.'));
        }
        $chunk = substr($this->data, $this->offset, $length);
        $this->offset += $length;
        return $chunk;
    }
}
