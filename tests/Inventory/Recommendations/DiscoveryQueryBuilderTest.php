<?php

declare(strict_types=1);

namespace Tests\Inventory\Recommendations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\Contracts\ProductDiscoveryInterface;
use Kanvas\Inventory\Recommendations\DataTransferObject\ProductIntent;
use Kanvas\Inventory\Recommendations\Discovery\Constraints\RangeConstraint;
use Kanvas\Inventory\Recommendations\Discovery\DiscoveryQuery;
use Kanvas\Inventory\Recommendations\Discovery\DiscoveryQueryBuilder;
use Kanvas\Inventory\Recommendations\Discovery\NumericFilter;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum;
use Kanvas\Inventory\Recommendations\Services\IntentLexiconService;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Traits\ManagesAppSettings;

class DiscoveryQueryBuilderTest extends TestCase
{
    use DatabaseTransactions;
    use ManagesAppSettings;

    public function testAKeywordEngineGetsTheSentenceWithoutStopWordsAndFilterPhrases(): void
    {
        $query = $this->build('a gift for my girlfriend under $50, something elegant', vectors: false);

        $this->assertStringNotContainsString('under', $query->text, 'The budget phrase is already a filter.');
        $this->assertStringNotContainsString('girlfriend', $query->text, 'The recipient is already a filter.');
        $this->assertStringNotContainsString('50', $query->text);
        $this->assertStringContainsString('elegant', $query->text);
    }

    public function testAVectorEngineGetsTheSentenceAsIs(): void
    {
        $sentence = 'a gift for my girlfriend under $50';

        $this->assertSame($sentence, $this->build($sentence, vectors: true)->text);
    }

    public function testKeepsIdentifiersAndFallsBackToTheOriginalWhenNormalizationEmptiesIt(): void
    {
        $this->assertSame('x5', $this->build('X5', vectors: false)->text);
        $this->assertSame('for my', $this->build('for my', vectors: false)->text, 'An emptied query searches its own words, it never browses.');
    }

    #[Group('serial')]
    public function testRangeFieldsAndNumericBoundsOnlyOnceTheMigrationIsVerified(): void
    {
        $intent = $this->intent('an SUV')->withOverrides(
            minPrice: 30000.0,
            maxPrice: 40000.0,
            numericFilters: [new NumericFilter('mileage', max: 100000.0)],
        );

        $before = $this->rangeFields(new DiscoveryQueryBuilder(app(Apps::class), $this->company())->build($intent, $this->adapter(false)));
        $this->assertSame(['price', 'price'], $before, 'Before the migration the old single-price filter stays and numeric fields are skipped.');

        $this->withRangeFiltersVerified(function () use ($intent): void {
            $after = new DiscoveryQueryBuilder(app(Apps::class), $this->company())->build($intent, $this->adapter(false));

            $this->assertSame(['price_max', 'price_min', 'num_mileage_min'], $this->rangeFields($after));
            $floor = $after->constraints[array_search('price_max', array_map(static fn ($c) => $c->field ?? null, $after->constraints), true)];
            $this->assertSame(0.0, $floor->alsoAllow, 'Unknown price (0) survives a floor.');
        });
    }

    private function build(string $sentence, bool $vectors): DiscoveryQuery
    {
        return new DiscoveryQueryBuilder(app(Apps::class), $this->company())->build($this->intent($sentence), $this->adapter($vectors));
    }

    /**
     * @return list<string>
     */
    private function rangeFields(DiscoveryQuery $query): array
    {
        return array_values(array_map(
            static fn (RangeConstraint $constraint): string => $constraint->field,
            array_filter($query->constraints, static fn (object $constraint): bool => $constraint instanceof RangeConstraint),
        ));
    }

    private function withRangeFiltersVerified(callable $test): void
    {
        $this->setAppSettings([ConfigurationEnum::RANGE_FILTERS->value => Products::DISCOVERY_SCHEMA_VERSION]);

        $test();
    }

    private function intent(string $sentence): ProductIntent
    {
        return ProductIntent::fromSentence($sentence, new IntentLexiconService(app(Apps::class)));
    }

    private function company(): mixed
    {
        return auth()->user()->getCurrentCompany();
    }

    private function adapter(bool $vectors): ProductDiscoveryInterface
    {
        return new class ($vectors) implements ProductDiscoveryInterface {
            public function __construct(private readonly bool $vectors)
            {
            }

            public function search(DiscoveryQuery $query, int $limit, ?array $tasteVector = null): array
            {
                return [];
            }

            public function supportsVectors(): bool
            {
                return $this->vectors;
            }

            public function canFilterOn(string $field): bool
            {
                return true;
            }
        };
    }
}
