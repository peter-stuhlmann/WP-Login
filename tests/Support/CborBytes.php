<?php

declare(strict_types=1);

namespace Portier\Tests\Support;

/**
 * A CBOR byte string for test data (PHP strings are encoded as text strings).
 */
final class CborBytes
{
    public function __construct(public readonly string $bytes)
    {
    }
}
