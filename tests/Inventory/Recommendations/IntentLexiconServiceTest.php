<?php

declare(strict_types=1);

namespace Tests\Inventory\Recommendations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Recommendations\DataTransferObject\ProductIntent;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum;
use Kanvas\Inventory\Recommendations\Services\IntentLexiconService;
use Tests\TestCase;

class IntentLexiconServiceTest extends TestCase
{
    use DatabaseTransactions;

    private mixed $originalLexicon = null;
    private bool $lexiconOverridden = false;

    private const array SPANISH_LEXICON = [
        'max_price' => ['menos de', 'hasta', 'no mas de', 'maximo'],
        'min_price' => ['mas de', 'desde', 'arriba de'],
        'premium' => ['caro', 'lujo', 'exclusivo'],
        'cheap' => ['barato', 'economico'],
    ];

    protected function tearDown(): void
    {
        if ($this->lexiconOverridden) {
            app(Apps::class)->set(ConfigurationEnum::INTENT_LEXICON->value, $this->originalLexicon);
            $this->lexiconOverridden = false;
        }

        parent::tearDown();
    }

    public function testMergesTenantTermsOverShippedEnglishRatherThanReplacingThem(): void
    {
        $lexicon = $this->lexiconWithSpanish();
        $terms = $lexicon->termsFor(IntentLexiconService::MAX_PRICE);

        $this->assertContains('menos de', $terms, 'Tenant Spanish terms must be present.');
        $this->assertContains('under', $terms, 'Shipped English terms must survive the merge — one storefront gets both.');
    }

    public function testNormalizesAccentsSoUnaccentedTypingStillMatches(): void
    {
        $this->assertSame('maximo', IntentLexiconService::normalize('Máximo'));
        $this->assertSame('mas de', IntentLexiconService::normalize('  MÁS DE  '));

        $lexicon = $this->lexiconWithAccentedTerms();

        $intent = ProductIntent::fromSentence('algo maximo 40 dolares', $lexicon);
        $this->assertSame(40.0, $intent->maxPrice, 'An accented lexicon term must match unaccented typing.');

        $intent = ProductIntent::fromSentence('algo máximo 40 dolares', $lexicon);
        $this->assertSame(40.0, $intent->maxPrice, 'And the accented spelling must still match.');
    }

    public function testLongerPhraseWinsSoNegatedComparatorDoesNotInvertTheFilter(): void
    {
        $lexicon = $this->lexiconWithSpanish();

        // "no mas de" (a maximum) contains "mas de" (a minimum). Without
        // longest-first ordering this parses as a floor and returns everything
        // ABOVE 50 to a customer who asked for under 50.
        $intent = ProductIntent::fromSentence('un regalo, no mas de 50', $lexicon);

        $this->assertSame(50.0, $intent->maxPrice);
        $this->assertNull($intent->minPrice);
    }

    public function testEnglishHasTheSameNegatedComparatorTrap(): void
    {
        $lexicon = $this->lexicon();

        $intent = ProductIntent::fromSentence('a gift, no more than 30', $lexicon);

        $this->assertSame(30.0, $intent->maxPrice);
        $this->assertNull($intent->minPrice);
    }

    public function testParsesCurrencySymbolAndThousandsSeparator(): void
    {
        $lexicon = $this->lexiconWithSpanish();

        $this->assertSame(50.0, ProductIntent::fromSentence('menos de $50', $lexicon)->maxPrice);
        $this->assertSame(1299.99, ProductIntent::fromSentence('under $1,299.99', $lexicon)->maxPrice);
        $this->assertSame(100.0, ProductIntent::fromSentence('mas de 100', $lexicon)->minPrice);
    }

    public function testDoesNotReadAnAgeAsABudget(): void
    {
        $lexicon = $this->lexiconWithSpanish();

        $intent = ProductIntent::fromSentence(
            'Recomiendame un regalo para una mujer de 32 años, creativa y amante del diseño y el café',
            $lexicon,
        );

        $this->assertNull($intent->maxPrice, 'A bare number with no currency symbol is not a price.');
        $this->assertNull($intent->minPrice);
        $this->assertFalse($intent->hasPriceConstraint());
    }

    public function testBareCurrencyAmountIsReadAsACeiling(): void
    {
        $intent = ProductIntent::fromSentence('algo bonito $40', $this->lexiconWithSpanish());

        $this->assertSame(40.0, $intent->maxPrice);
    }

    public function testVaguePriceBandOnlyAppliesWithoutAnExplicitNumber(): void
    {
        $lexicon = $this->lexiconWithSpanish();
        $premiumFloor = $lexicon->premiumMinPrice();
        $cheapCeiling = $lexicon->cheapMaxPrice();

        $premium = ProductIntent::fromSentence('algo de lujo para mi hermano', $lexicon);
        $this->assertSame($premiumFloor, $premium->minPrice);
        $this->assertNull($premium->maxPrice);

        $cheap = ProductIntent::fromSentence('algo barato', $lexicon);
        $this->assertSame($cheapCeiling, $cheap->maxPrice);

        // An explicit number always wins over the vague band.
        $explicit = ProductIntent::fromSentence('algo de lujo, menos de $80', $lexicon);
        $this->assertSame(80.0, $explicit->maxPrice);
        $this->assertNull($explicit->minPrice);
    }

    public function testKeepsTheRawSentenceForTheEmbedding(): void
    {
        $sentence = 'Un regalo para mi MEJOR amiga, menos de $50';

        // Normalization exists for regex matching only — stripping accents and
        // case before embedding would degrade retrieval.
        $this->assertSame($sentence, ProductIntent::fromSentence($sentence, $this->lexiconWithSpanish())->sentence);
    }

    public function testEmptyLexiconYieldsNoPatternAndNoCrash(): void
    {
        config(['inventory-discovery.intent_lexicon' => []]);

        $lexicon = $this->lexiconWithTenantTerms([]);

        $this->assertNull($lexicon->patternFor(IntentLexiconService::MAX_PRICE));
        $this->assertFalse($lexicon->matches('algo barato', IntentLexiconService::CHEAP));

        $intent = ProductIntent::fromSentence('menos de 50', $lexicon);
        $this->assertNull($intent->maxPrice);
    }

    public function testTenantOverridesThePriceBandThresholds(): void
    {
        $app = app(Apps::class);
        $originalPremium = $app->get(ConfigurationEnum::PREMIUM_MIN_PRICE->value);
        $app->set(ConfigurationEnum::PREMIUM_MIN_PRICE->value, 250);

        try {
            $this->assertSame(250.0, $this->lexicon()->premiumMinPrice());
        } finally {
            $app->set(ConfigurationEnum::PREMIUM_MIN_PRICE->value, $originalPremium);
        }
    }

    private function lexicon(): IntentLexiconService
    {
        return new IntentLexiconService(app(Apps::class));
    }

    private function lexiconWithSpanish(): IntentLexiconService
    {
        return $this->lexiconWithTenantTerms(self::SPANISH_LEXICON);
    }

    private function lexiconWithAccentedTerms(): IntentLexiconService
    {
        return $this->lexiconWithTenantTerms(['max_price' => ['máximo']]);
    }

    /**
     * Writes the lexicon to the real app setting rather than stubbing Apps: an
     * anonymous subclass of an Eloquent model breaks on `new static` inside
     * HasEvents, and the real path also exercises get()'s JSON handling.
     */
    private function lexiconWithTenantTerms(array $lexicon): IntentLexiconService
    {
        $app = app(Apps::class);

        if (! $this->lexiconOverridden) {
            $this->originalLexicon = $app->get(ConfigurationEnum::INTENT_LEXICON->value);
            $this->lexiconOverridden = true;
        }

        $app->set(ConfigurationEnum::INTENT_LEXICON->value, $lexicon);

        return new IntentLexiconService($app);
    }
}
