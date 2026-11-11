<?php

declare(strict_types=1);

namespace Tests\Unit\Onboarding;

use Kanvas\Companies\Onboarding\Support\WebsiteUrl;
use Kanvas\Exceptions\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCaseUnit;

final class WebsiteUrlTest extends TestCaseUnit
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function typedWebsites(): array
    {
        return [
            'bare domain' => ['mctekk.com', 'https://mctekk.com'],
            'well formed' => ['https://mctekk.com/', 'https://mctekk.com'],
            'http kept' => ['http://mctekk.com', 'http://mctekk.com'],
            'missing colon' => ['https//mctekk.com', 'https://mctekk.com'],
            'one slash' => ['https:/mctekk.com', 'https://mctekk.com'],
            'no slashes' => ['https:mctekk.com', 'https://mctekk.com'],
            'three slashes' => ['https:///mctekk.com', 'https://mctekk.com'],
            'scheme typed twice' => ['https://https//mctekk.com', 'https://mctekk.com'],
            'uppercase scheme' => ['HTTPS//MCTEKK.COM/About/', 'https://mctekk.com/About'],
            'www and path' => ['www.mctekk.com/about', 'https://www.mctekk.com/about'],
        ];
    }

    #[DataProvider('typedWebsites')]
    public function testNormalizesWhatPeopleActuallyType(string $typed, string $expected): void
    {
        $this->assertSame($expected, WebsiteUrl::normalize($typed));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedWebsites(): array
    {
        return [
            'empty' => [''],
            'bare word' => ['mctekk'],
            'scheme only' => ['https://'],
            'ftp' => ['ftp://mctekk.com'],
            'localhost' => ['localhost'],
            'internal name' => ['intranet.internal'],
            'private ip' => ['http://127.0.0.1/admin'],
        ];
    }

    #[DataProvider('rejectedWebsites')]
    public function testRefusesWhatCannotBeAPublicWebsite(string $typed): void
    {
        $this->expectException(ValidationException::class);

        WebsiteUrl::normalize($typed);
    }

    public function testWithSchemeRepairsAMistypedSchemeForTheCollector(): void
    {
        $this->assertSame('https://mctekk.com', WebsiteUrl::withScheme('https//mctekk.com'));
        $this->assertSame('https://mctekk.com', WebsiteUrl::withScheme('mctekk.com'));
        $this->assertSame('http://mctekk.com', WebsiteUrl::withScheme('http://mctekk.com'));
    }
}
