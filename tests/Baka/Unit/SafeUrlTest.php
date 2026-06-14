<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Http\Exceptions\SsrfException;
use Baka\Http\SafeUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCaseUnit;

final class SafeUrlTest extends TestCaseUnit
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function privateUrlProvider(): array
    {
        return [
            'loopback v4' => ['http://127.0.0.1/'],
            'loopback v6' => ['http://[::1]/'],
            'private 10/8' => ['http://10.0.0.1/'],
            'private 172.16/12' => ['http://172.16.5.4/'],
            'private 192.168/16' => ['http://192.168.1.1/'],
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/iam/'],
            'zero network' => ['http://0.0.0.0/'],
            'ipv6 unique-local' => ['http://[fc00::1]/'],
            'cgnat' => ['http://100.64.1.1/'],
            'ipv4-mapped v6' => ['http://[::ffff:10.0.0.1]/'],
            'ipv4-compatible v6' => ['http://[::7f00:1]/'],
            'nat64 embeds loopback' => ['http://[64:ff9b::7f00:1]/'],
            '6to4 embeds loopback' => ['http://[2002:7f00:1::]/'],
            'teredo' => ['http://[2001::1]/'],
        ];
    }

    #[DataProvider('privateUrlProvider')]
    public function testRejectsPrivateAndReservedHosts(string $url): void
    {
        $this->expectException(SsrfException::class);
        SafeUrl::assertSafe($url);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badSchemeProvider(): array
    {
        return [
            'file' => ['file:///etc/passwd'],
            'gopher' => ['gopher://127.0.0.1/_'],
            'ftp' => ['ftp://example.com/x'],
            'dict' => ['dict://localhost:11211/'],
        ];
    }

    #[DataProvider('badSchemeProvider')]
    public function testRejectsDisallowedSchemes(string $url): void
    {
        $this->expectException(SsrfException::class);
        SafeUrl::assertSafe($url);
    }

    public function testRejectsMalformedUrl(): void
    {
        $this->expectException(SsrfException::class);
        SafeUrl::assertSafe('not a url');
    }

    public function testAllowsPublicIpLiterals(): void
    {
        $this->assertSame(['1.1.1.1'], SafeUrl::resolve('https://1.1.1.1/'));
        $this->assertSame(['8.8.8.8'], SafeUrl::resolve('http://8.8.8.8/image.png'));
    }

    public function testIsPublicIpClassification(): void
    {
        $this->assertTrue(SafeUrl::isPublicIp('1.1.1.1'));
        $this->assertTrue(SafeUrl::isPublicIp('2001:4860:4860::8888'));
        $this->assertFalse(SafeUrl::isPublicIp('127.0.0.1'));
        $this->assertFalse(SafeUrl::isPublicIp('10.0.0.1'));
        $this->assertFalse(SafeUrl::isPublicIp('169.254.169.254'));
        $this->assertFalse(SafeUrl::isPublicIp('100.64.0.1'));
        $this->assertFalse(SafeUrl::isPublicIp('::1'));
    }

    public function testIpInCidrMatchesV4AndV6(): void
    {
        $this->assertTrue(SafeUrl::ipInCidr('10.1.2.3', '10.0.0.0/8'));
        $this->assertFalse(SafeUrl::ipInCidr('11.0.0.1', '10.0.0.0/8'));
        $this->assertTrue(SafeUrl::ipInCidr('100.64.5.5', '100.64.0.0/10'));
        $this->assertTrue(SafeUrl::ipInCidr('fc00::1', 'fc00::/7'));
        $this->assertFalse(SafeUrl::ipInCidr('2001:4860::1', 'fc00::/7'));
    }
}
