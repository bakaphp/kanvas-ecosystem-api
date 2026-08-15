<?php

declare(strict_types=1);

namespace Tests\Souk\Unit;

use Kanvas\Souk\Affiliates\Activities\CreateAffiliateConversionActivity;
use Tests\TestCaseUnit;

final class CreateAffiliateConversionMappingTest extends TestCaseUnit
{
    /**
     * @return array<string, string>
     */
    private function mapping(): array
    {
        return [
            'RD' => 'UA20',
            'DO' => 'UA20',
            'CR' => 'UA20-1',
            'GT' => 'UA20-2',
        ];
    }

    public function testForwardResolvesLinkCodeToAffiliateId(): void
    {
        [$link, $id, $shortCode] = CreateAffiliateConversionActivity::resolveFromMapping('CR', null, null, $this->mapping());

        $this->assertSame('UA20-1', $link);
        $this->assertSame('UA20-1', $id);
        $this->assertSame('CR', $shortCode);
    }

    public function testReverseResolvesAffiliateIdCaseInsensitively(): void
    {
        // Order carried the affiliate id ("ua20") directly instead of a link code — the failing case.
        [$link, $id, $shortCode] = CreateAffiliateConversionActivity::resolveFromMapping(null, 'ua20', 'CA', $this->mapping());

        $this->assertSame('UA20', $link);
        $this->assertSame('UA20', $id);
        // First mapping key whose value matches wins.
        $this->assertSame('RD', $shortCode);
    }

    public function testReverseResolvesExactCasing(): void
    {
        [$link, $id] = CreateAffiliateConversionActivity::resolveFromMapping(null, 'UA20-2', null, $this->mapping());

        $this->assertSame('UA20-2', $link);
        $this->assertSame('UA20-2', $id);
    }

    public function testUnknownAffiliateIdIsLeftUntouched(): void
    {
        [$link, $id, $shortCode] = CreateAffiliateConversionActivity::resolveFromMapping(null, 'zz99', 'CA', $this->mapping());

        $this->assertNull($link);
        $this->assertSame('zz99', $id);
        $this->assertSame('CA', $shortCode);
    }

    public function testEmptyMappingIsLeftUntouched(): void
    {
        [$link, $id, $shortCode] = CreateAffiliateConversionActivity::resolveFromMapping('RD', 'ua20', 'CA', []);

        $this->assertSame('RD', $link);
        $this->assertSame('ua20', $id);
        $this->assertSame('CA', $shortCode);
    }
}
