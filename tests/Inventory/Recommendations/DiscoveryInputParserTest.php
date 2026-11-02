<?php

declare(strict_types=1);

namespace Tests\Inventory\Recommendations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Recommendations\Discovery\DiscoveryInputParser;
use Kanvas\Inventory\Recommendations\Enums\AudienceEnum;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum;
use Kanvas\Inventory\Recommendations\Exceptions\InvalidDiscoveryInputException;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Traits\ManagesAppSettings;

#[Group('serial')]
class DiscoveryInputParserTest extends TestCase
{
    use DatabaseTransactions;
    use ManagesAppSettings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setAppSettings([
            ConfigurationEnum::NUMERIC_ATTRIBUTES->value => [
                'mileage' => ['attribute' => 'Mileage', 'unit' => 'mi'],
                'year' => ['attribute' => 'Year'],
            ],
        ]);
    }

    public function testExplicitFiltersOverrideWhatWasParsedFromTheSentence(): void
    {
        $intent = $this->parser()->parse([
            'request' => 'do you have an SUV under 100k miles, no more than $20 thousand',
            'search_query' => 'SUV',
            'max_price' => 18000,
            'numeric_filters' => [['field' => 'mileage', 'max' => 100000, 'unit' => 'mi']],
            'audience' => 'male',
        ]);

        $this->assertSame('SUV', $intent->query);
        $this->assertSame(18000.0, $intent->maxPrice, 'The agent\'s explicit budget wins over the parsed one.');
        $this->assertSame(AudienceEnum::MALE, $intent->audience);
        $this->assertSame(100000.0, $intent->numericFilters[0]->max);
        $this->assertSame('do you have an SUV under 100k miles, no more than $20 thousand', $intent->sentence, 'The verbatim request is kept for the vector half and the log.');
    }

    public function testKilometresAreConvertedToTheFieldsCanonicalMiles(): void
    {
        $intent = $this->parser()->parse([
            'request' => 'menos de 160934 km',
            'numeric_filters' => [['field' => 'mileage', 'max' => 160934, 'unit' => 'km']],
        ]);

        $this->assertEqualsWithDelta(100000.0, $intent->numericFilters[0]->max, 1.0);
    }

    public function testAnUnknownNumericFieldIsRejectedWithTheAllowedOnes(): void
    {
        $error = $this->rejection(['request' => 'a car', 'numeric_filters' => [['field' => 'odometer', 'max' => 100000]]]);

        $this->assertSame('odometer', $error['field']);
        $this->assertSame('unknown_field', $error['reason']);
        $this->assertSame(['mileage (mi)', 'year'], $error['allowed'], 'Dropping it would silently remove the limit.');
    }

    public function testAUnitThatCannotConvertIsRejected(): void
    {
        $error = $this->rejection(['request' => 'a car', 'numeric_filters' => [['field' => 'mileage', 'max' => 50, 'unit' => 'liters']]]);

        $this->assertStringStartsWith('invalid_unit', $error['reason']);
    }

    public function testContradictoryRangesAreRejected(): void
    {
        $this->assertSame('price', $this->rejection(['request' => 'a car', 'min_price' => 40000, 'max_price' => 30000])['field']);
        $this->assertStringStartsWith(
            'contradictory_range',
            $this->rejection(['request' => 'a car', 'numeric_filters' => [['field' => 'year', 'min' => 2022, 'max' => 2018]]])['reason'],
        );
    }

    public function testAnUnknownAudienceOrCategoryIsRejected(): void
    {
        $this->assertSame('audience', $this->rejection(['request' => 'a gift', 'audience' => 'robots'])['field']);
        $this->assertSame('unknown_category', $this->rejection(['request' => 'a gift', 'category' => 'Definitely Not A Category 9f3a'])['reason']);
    }

    public function testSoftInputIsCappedNeverRejected(): void
    {
        $intent = $this->parser()->parse([
            'request' => 'a phone case',
            'search_query' => 'phone case',
            'preferences' => ['black', ' ', 'black', 'leather', 'slim', 'matte', 'red', 'blue'],
            'alternative_queries' => ['phone cover', 'mobile case', 'phone sleeve'],
        ]);

        $this->assertSame(['black', 'leather', 'slim', 'matte', 'red'], $intent->preferences);
        $this->assertCount(2, $intent->alternativeQueries);
    }

    public function testAMissingRequestIsRejected(): void
    {
        $this->assertSame('request', $this->rejection([])['field']);
    }

    private function parser(): DiscoveryInputParser
    {
        return new DiscoveryInputParser(app(Apps::class), auth()->user()->getCurrentCompany());
    }

    private function rejection(array $input): array
    {
        try {
            $this->parser()->parse($input);
        } catch (InvalidDiscoveryInputException $e) {
            return $e->toArray();
        }

        $this->fail('Expected the input to be rejected.');
    }
}
