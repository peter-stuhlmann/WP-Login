<?php

declare(strict_types=1);

namespace Portier\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Portier\Security\ClientIp;

/**
 * @covers \Portier\Security\ClientIp
 */
final class ClientIpTest extends TestCase
{
    public function test_uses_remote_addr_by_default(): void
    {
        $this->assertSame('203.0.113.7', ClientIp::resolve(['REMOTE_ADDR' => '203.0.113.7']));
    }

    public function test_ignores_proxy_headers_unless_trusted(): void
    {
        $server = ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1'];
        $this->assertSame('203.0.113.7', ClientIp::resolve($server), 'A forged header must not change the IP.');
    }

    public function test_forwarded_for_uses_the_address_the_proxy_appended(): void
    {
        // The client sent a forged "198.51.100.1"; the proxy appended the real address.
        $server = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1, 203.0.113.50'];
        $this->assertSame('203.0.113.50', ClientIp::resolve($server, 'HTTP_X_FORWARDED_FOR'));
    }

    public function test_forwarded_for_skips_internal_proxies_and_garbage(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1, 203.0.113.50, garbage, 10.0.0.2'];
        $this->assertSame('203.0.113.50', ClientIp::resolve($server, 'HTTP_X_FORWARDED_FOR'));
    }

    public function test_forwarded_for_inside_a_private_network_uses_the_last_address(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '10.0.0.7, 192.168.1.2'];
        $this->assertSame('192.168.1.2', ClientIp::resolve($server, 'HTTP_X_FORWARDED_FOR'));
    }

    public function test_single_address_headers_must_hold_one_address(): void
    {
        $server = ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_CF_CONNECTING_IP' => '198.51.100.1, 198.51.100.2'];
        $this->assertSame('203.0.113.7', ClientIp::resolve($server, 'HTTP_CF_CONNECTING_IP'));
        $this->assertSame('198.51.100.1', ClientIp::resolve(['HTTP_CF_CONNECTING_IP' => ' 198.51.100.1 '], 'HTTP_CF_CONNECTING_IP'));
    }

    public function test_falls_back_to_remote_addr_when_the_trusted_header_is_missing(): void
    {
        $this->assertSame('10.0.0.1', ClientIp::resolve(['REMOTE_ADDR' => '10.0.0.1'], 'HTTP_CF_CONNECTING_IP'));
    }

    public function test_supports_ipv6(): void
    {
        $this->assertSame('2001:db8::1', ClientIp::resolve(['REMOTE_ADDR' => '2001:db8::1']));
    }

    public function test_returns_a_placeholder_for_invalid_input(): void
    {
        $this->assertSame('0.0.0.0', ClientIp::resolve(['REMOTE_ADDR' => 'not-an-ip']));
        $this->assertSame('0.0.0.0', ClientIp::resolve([]));
    }
}
