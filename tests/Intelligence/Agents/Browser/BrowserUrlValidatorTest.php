<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Browser;

use Kanvas\Intelligence\Agents\Neuron\Browser\BrowserErrorCode;
use Kanvas\Intelligence\Agents\Neuron\Browser\BrowserToolException;
use Kanvas\Intelligence\Agents\Neuron\Browser\BrowserUrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BrowserUrlValidatorTest extends TestCase
{
    #[DataProvider('unsafeUrls')]
    public function testItRejectsUnsafeUrls(string $url): void
    {
        $validator = new class () extends BrowserUrlValidator {
            protected function resolveAddresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        };

        try {
            $validator->validate($url);
            $this->fail('Expected an INVALID_URL exception.');
        } catch (BrowserToolException $exception) {
            $this->assertSame(BrowserErrorCode::INVALID_URL, $exception->errorCode);
        }
    }

    public function testItRejectsAHostnameThatResolvesToAPrivateAddress(): void
    {
        $validator = new class () extends BrowserUrlValidator {
            protected function resolveAddresses(string $host): array
            {
                return ['10.0.0.25'];
            }
        };

        $this->expectException(BrowserToolException::class);
        $validator->validate('https://public-looking.example/path');
    }

    public function testItAllowsAnExplicitFixtureHost(): void
    {
        $validator = new BrowserUrlValidator(['browser-fixture']);

        $this->assertSame(
            'http://browser-fixture/',
            $validator->validate('http://browser-fixture/'),
        );
    }

    public function testItAllowsPublicHttpAndHttpsUrls(): void
    {
        $validator = new class () extends BrowserUrlValidator {
            protected function resolveAddresses(string $host): array
            {
                return ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'];
            }
        };

        $this->assertSame('https://example.com/', $validator->validate('https://example.com/'));
    }

    public static function unsafeUrls(): array
    {
        return [
            ['file:///etc/passwd'],
            ['javascript:alert(1)'],
            ['data:text/plain,hello'],
            ['http://localhost/private'],
            ['http://user:password@example.com/'],
        ];
    }
}
