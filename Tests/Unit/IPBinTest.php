<?php

namespace Tests\Unit;

use Auth\IPBin;
use Codeception\Test\Unit;

class IPBinTest extends Unit
{
    // === ipToBinary tests ===

    public function testIpv4ToBinary()
    {
        $binary = IPBin::ipToBinary('192.168.1.1');
        $this->assertEquals(4, strlen($binary), 'IPv4 should produce 4-byte binary');
    }

    public function testIpv6ToBinary()
    {
        $binary = IPBin::ipToBinary('::1');
        $this->assertEquals(16, strlen($binary), 'IPv6 should produce 16-byte binary');
    }

    public function testInvalidIpToBinary()
    {
        $binary = IPBin::ipToBinary('not-an-ip');
        $this->assertEquals('', $binary, 'Invalid IP should return empty string');
    }

    public function testEmptyIpToBinary()
    {
        $binary = IPBin::ipToBinary('');
        $this->assertEquals('', $binary, 'Empty string should return empty string');
    }

    // === binaryToIp tests ===

    public function testBinaryToIpv4()
    {
        $binary = inet_pton('192.168.1.1');
        $ip = IPBin::binaryToIp($binary);
        $this->assertEquals('192.168.1.1', $ip);
    }

    public function testBinaryToIpv6()
    {
        $binary = inet_pton('::1');
        $ip = IPBin::binaryToIp($binary);
        $this->assertEquals('::1', $ip);
    }

    public function testInvalidBinaryLength()
    {
        $ip = IPBin::binaryToIp('abc');  // 3 bytes, not 4 or 16
        $this->assertEquals('', $ip, 'Invalid binary length should return empty string');
    }

    public function testEmptyBinaryToIp()
    {
        $ip = IPBin::binaryToIp('');
        $this->assertEquals('', $ip, 'Empty binary should return empty string');
    }

    // === Roundtrip tests ===

    public function testIpv4Roundtrip()
    {
        $original = '10.0.0.1';
        $binary = IPBin::ipToBinary($original);
        $result = IPBin::binaryToIp($binary);
        $this->assertEquals($original, $result);
    }

    public function testIpv6Roundtrip()
    {
        $original = '2001:db8::1';
        $binary = IPBin::ipToBinary($original);
        $result = IPBin::binaryToIp($binary);
        // IPv6 may be normalized, so compare binary
        $this->assertEquals($binary, inet_pton($result));
    }
}
