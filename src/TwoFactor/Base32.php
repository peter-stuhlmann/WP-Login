<?php

declare(strict_types=1);

namespace Portier\TwoFactor;

/**
 * RFC 4648 Base32 (the encoding authenticator apps expect for TOTP secrets).
 */
final class Base32
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[(int) bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $bytes === '' ? '' : $out;
    }

    /**
     * Decodes Base32; spaces, hyphens and lowercase letters are tolerated (people type keys).
     *
     * @throws \InvalidArgumentException For characters outside the alphabet.
     */
    public static function decode(string $encoded): string
    {
        $encoded = strtoupper((string) preg_replace('/[\s\-=]/', '', $encoded));
        $bits = '';
        foreach (str_split($encoded) as $char) {
            $value = strpos(self::ALPHABET, $char);
            if ($value === false) {
                throw new \InvalidArgumentException(esc_html('Invalid Base32 character.'));
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr((int) bindec($byte));
            }
        }
        return $encoded === '' ? '' : $out;
    }
}
