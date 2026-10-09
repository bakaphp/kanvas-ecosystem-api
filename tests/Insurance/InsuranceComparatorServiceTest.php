<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Kanvas\Insurance\DataTransferObject\InsuranceSearchEntry;
use Kanvas\Insurance\DataTransferObject\QuoteResult;
use Kanvas\Insurance\Enums\InsuranceCoverageCategoryEnum;
use Kanvas\Insurance\Services\InsuranceComparatorService;
use PHPUnit\Framework\TestCase;

class InsuranceComparatorServiceTest extends TestCase
{
    private function entry(
        int $planId,
        float $price = 100.0,
        bool $sponsored = false,
        float $commission = 0.0,
        int $score = 0,
        ?bool $canEmit = null,
    ): InsuranceSearchEntry {
        return $this->entryWithQuote(
            $planId,
            new QuoteResult(
                success: true,
                message: 'ok',
                quoteNumber: 'Q' . $planId,
                total: $price,
                canEmit: $canEmit,
            ),
            $sponsored,
            $commission,
            $score,
        );
    }

    private function entryWithQuote(
        int $planId,
        QuoteResult $quote,
        bool $sponsored = false,
        float $commission = 0.0,
        int $score = 0,
    ): InsuranceSearchEntry {
        return new InsuranceSearchEntry(
            insurerId: 1,
            insurerName: 'Insurer',
            insurerLogo: null,
            provider: 'fake',
            planId: $planId,
            planName: 'Plan ' . $planId,
            planSlug: 'plan-' . $planId,
            category: InsuranceCoverageCategoryEnum::FULL,
            deductible: null,
            validityDays: null,
            sponsored: $sponsored,
            recommendedScore: $score,
            commission: $commission,
            quote: $quote,
        );
    }

    private function ranked(array $entries): array
    {
        return array_map(
            fn (InsuranceSearchEntry $entry): int => $entry->planId,
            new InsuranceComparatorService()->rank($entries)
        );
    }

    public function testSponsoredBeatsEverythingElse(): void
    {
        $this->assertSame([2, 1], $this->ranked([
            $this->entry(
                1,
                price: 10,
                commission: 500,
                score: 99,
                canEmit: true,
            ),
            $this->entry(2, price: 9000, sponsored: true),
        ]));
    }

    public function testHigherCommissionWinsWhenSponsorshipTies(): void
    {
        $this->assertSame([2, 3, 1], $this->ranked([
            $this->entry(
                1,
                price: 10,
                commission: 5,
                score: 99,
            ),
            $this->entry(2, price: 500, commission: 50),
            $this->entry(3, price: 400, commission: 20),
        ]));
    }

    public function testRecommendedScoreWinsWhenCommissionTies(): void
    {
        $this->assertSame([2, 1], $this->ranked([
            $this->entry(
                1,
                price: 10,
                commission: 10,
                score: 1,
            ),
            $this->entry(
                2,
                price: 900,
                commission: 10,
                score: 7,
            ),
        ]));
    }

    public function testInsurerSayingItCanEmitBeatsOneThatCannotWhenScoresTie(): void
    {
        $this->assertSame([3, 2, 1], $this->ranked([
            $this->entry(
                1,
                price: 10,
                score: 5,
                canEmit: false,
            ),
            $this->entry(
                2,
                price: 20,
                score: 5,
                canEmit: null,
            ),
            $this->entry(
                3,
                price: 30,
                score: 5,
                canEmit: true,
            ),
        ]));
    }

    public function testLowestPriceDecidesWhenEverythingElseTies(): void
    {
        $this->assertSame([2, 3, 1], $this->ranked([
            $this->entry(1, price: 300),
            $this->entry(2, price: 100),
            $this->entry(3, price: 200),
        ]));
    }

    public function testPlanIdBreaksAFullTieDeterministicallyRegardlessOfInputOrder(): void
    {
        $this->assertSame([4, 7, 9], $this->ranked([$this->entry(9), $this->entry(4), $this->entry(7)]));
        $this->assertSame([4, 7, 9], $this->ranked([$this->entry(7), $this->entry(9), $this->entry(4)]));
    }

    public function testUnpricedQuotesSortLastWithinTheirTier(): void
    {
        $unpriced = $this->entryWithQuote(1, new QuoteResult(success: true, message: 'ok', quoteNumber: 'Q1'));

        $this->assertSame([2, 1], $this->ranked([$unpriced, $this->entry(2, price: 999999)]));
    }

    public function testIsRecommendedNeedsAScoreAndAnInsurerThatDoesNotRefuse(): void
    {
        $this->assertTrue($this->entry(1, score: 3, canEmit: true)->isRecommended());
        $this->assertTrue($this->entry(1, score: 3, canEmit: null)->isRecommended());
        $this->assertFalse($this->entry(1, score: 3, canEmit: false)->isRecommended());
        $this->assertFalse($this->entry(1, score: 0, canEmit: true)->isRecommended());
    }
}
