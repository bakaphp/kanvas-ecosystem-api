<?php

declare(strict_types=1);

namespace Tests\Unit\Imports;

use Baka\Http\SafeUrl;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Imports\RemoteFiles\RemoteFileClientFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCaseUnit;

class RemoteFileClientFactoryTest extends TestCaseUnit
{
    public static function privateHosts(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'rfc1918' => ['10.0.0.5'],
            'cloud metadata' => ['169.254.169.254'],
            'ipv6 loopback' => ['::1'],
            'nat64 smuggling a private ipv4' => ['64:ff9b::a00:5'],
        ];
    }

    #[DataProvider('privateHosts')]
    public function testRejectsHostsThatResolveToPrivateAddresses(string $host): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('non-public address');

        RemoteFileClientFactory::assertConnectable($host, 21);
    }

    public function testAcceptsAPublicAddressAndReturnsItForPinning(): void
    {
        $this->assertSame(['8.8.8.8'], RemoteFileClientFactory::assertConnectable('8.8.8.8', 22));
        $this->assertSame(['8.8.8.8'], SafeUrl::resolvePublicHost('8.8.8.8'));
    }

    public function testRejectsPortsOutsideTheAllowList(): void
    {
        $this->expectException(ValidationException::class);

        RemoteFileClientFactory::assertConnectable('8.8.8.8', 6379);
    }
}
