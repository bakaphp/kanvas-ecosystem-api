<?php

declare(strict_types=1);

namespace Tests\Connectors\ScrapperApi;

use Kanvas\Connectors\ScrapperApi\Services\CustomsTariffService;
use PHPUnit\Framework\TestCase;

class CustomsTariffServiceTest extends TestCase
{
    public function testFindsCodeExactly(): void
    {
        $tariff = CustomsTariffService::find('8517.13.00');

        $this->assertNotNull($tariff);
        $this->assertSame('8517.13.00', $tariff->code);
        $this->assertSame(20, $tariff->rate);
        $this->assertFalse($tariff->itbisExempt);
        $this->assertSame('Teléfonos inteligentes', $tariff->name);
    }

    public function testAcceptsCodesWithoutSeparators(): void
    {
        $this->assertSame('8517.13.00', CustomsTariffService::find('85171300')?->code);
        $this->assertSame('8517.13.00', CustomsTariffService::find('8517-13-00')?->code);
    }

    public function testResolvesSixDigitSubheadingToItsResidualLine(): void
    {
        $tariff = CustomsTariffService::find('6403.99');

        $this->assertNotNull($tariff);
        $this->assertSame('6403.99.90', $tariff->code);
        $this->assertSame(20, $tariff->rate);
    }

    /**
     * The last subheading of a heading is usually "Partes" at 0%, so falling back to
     * it would leave a phone duty-free. The highest duty in the branch is used instead.
     */
    public function testFourDigitHeadingResolvesToHighestDutyInBranch(): void
    {
        $tariff = CustomsTariffService::find('8517');

        $this->assertNotNull($tariff);
        $this->assertSame(20, $tariff->rate);
        $this->assertGreaterThan(0, $tariff->rate);
    }

    public function testReturnsNullForUnknownOrMalformedCodes(): void
    {
        $this->assertNull(CustomsTariffService::find('0000.00.00'));
        $this->assertNull(CustomsTariffService::find('abc'));
        $this->assertNull(CustomsTariffService::find(''));
        $this->assertNull(CustomsTariffService::find('12'));
    }

    public function testFlagsItbisExemptGoods(): void
    {
        $this->assertTrue(CustomsTariffService::find('4901.99.00')->itbisExempt, 'books are ITBIS exempt');
        $this->assertTrue(CustomsTariffService::find('3004.20.00')->itbisExempt, 'medicine is ITBIS exempt');
        $this->assertFalse(CustomsTariffService::find('6109.10.00')->itbisExempt, 't-shirts are not');
    }

    public function testKnownDutyRatesMatchTheOfficialSchedule(): void
    {
        $expected = [
            '8471.30.00' => 0,   // laptops
            '8517.13.00' => 20,  // smartphones
            '8518.30.00' => 14,  // headphones
            '8525.81.12' => 0,   // security cameras, Ley 171-12
            '9506.62.20' => 8,   // basketballs
            '6109.10.00' => 20,  // cotton t-shirts
            '2106.90.92' => 3,   // dietary supplements
        ];

        foreach ($expected as $code => $rate) {
            $this->assertSame($rate, CustomsTariffService::find($code)?->rate, "duty mismatch for {$code}");
        }
    }

    /**
     * Canary on the generated dataset: a botched regeneration usually shows up as a
     * different code count long before anyone notices a wrong duty in production.
     */
    public function testScheduleIsCompletelyLoaded(): void
    {
        $this->assertSame(7694, CustomsTariffService::count());
    }

    public function testEveryKeywordMapCodeExistsInTheSchedule(): void
    {
        $map = require __DIR__ . '/../../../src/Domains/Connectors/ScrapperApi/Resources/arancel_keyword_map.php';

        $this->assertNotEmpty($map);

        foreach ($map as $rule) {
            $this->assertTrue(
                CustomsTariffService::has($rule['code']),
                "keyword rule '{$rule['pattern']}' points at unknown code {$rule['code']}"
            );
        }
    }

    public function testEveryKeywordMapPatternCompiles(): void
    {
        $map = require __DIR__ . '/../../../src/Domains/Connectors/ScrapperApi/Resources/arancel_keyword_map.php';

        foreach ($map as $rule) {
            $this->assertNotFalse(
                @preg_match('/(?<![a-z0-9])(' . $rule['pattern'] . ')(?:e?s)?(?![a-z0-9])/i', 'probe'),
                "keyword rule '{$rule['pattern']}' is not a valid regex"
            );
        }
    }
}
