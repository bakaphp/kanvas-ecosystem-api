<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Discovery;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Number;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\Contracts\ProductDiscoveryInterface;
use Kanvas\Inventory\Recommendations\DataTransferObject\ProductIntent;
use Kanvas\Inventory\Recommendations\Discovery\Constraints\EqualsConstraint;
use Kanvas\Inventory\Recommendations\Discovery\Constraints\InConstraint;
use Kanvas\Inventory\Recommendations\Discovery\Constraints\RangeConstraint;
use Kanvas\Inventory\Recommendations\Enums\AudienceEnum;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum;
use Kanvas\Inventory\Recommendations\Enums\SearchFieldEnum;
use Kanvas\Inventory\Recommendations\Services\IntentLexiconService;
use Kanvas\Inventory\Recommendations\Services\ProductDiscoverySettings;
use Kanvas\Inventory\Recommendations\Services\SearchTermTokenizerService;

/**
 * Every business rule about what a search may return lives here, once, so the
 * Typesense, Algolia and SQL adapters cannot drift. The engine filter only
 * narrows the pool — RecommendProductsAction re-checks every hard constraint
 * against the database afterwards.
 */
class DiscoveryQueryBuilder
{
    public const int MAX_ALTERNATIVES = 2;

    private const array LEXICON_BUCKETS = [
        IntentLexiconService::MAX_PRICE,
        IntentLexiconService::MIN_PRICE,
        IntentLexiconService::PREMIUM,
        IntentLexiconService::CHEAP,
    ];

    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
    ) {
    }

    public function build(ProductIntent $intent, ProductDiscoveryInterface $adapter): DiscoveryQuery
    {
        $text = $this->searchText($intent, $adapter);

        return new DiscoveryQuery(
            text: $text,
            optionalWords: $this->optionalWords($intent, $text),
            alternatives: $this->alternatives($intent),
            constraints: $this->constraints($intent, $adapter),
            vectorText: $intent->sentence,
        );
    }

    /**
     * Whether the index carries the min/max range fields for this app. Until the
     * migration is verified (verify-discovery-index), a filter on a missing field
     * would exclude every record, so the old single-price filter stays.
     */
    public function rangeFiltersReady(): bool
    {
        $verified = $this->app->get(ConfigurationEnum::RANGE_FILTERS->value);

        return is_numeric($verified) && (int) $verified >= Products::DISCOVERY_SCHEMA_VERSION;
    }

    /**
     * The agent's catalog-terms query plus its preferences — optionalWords only
     * marks words already in the query, so a preference left out boosts nothing.
     * Without one, a vector engine gets the shopper's sentence as is; a keyword
     * engine gets it with the stop words and the phrases already turned into
     * filters ("menos de $50", "para mi novia") removed, or every word of a
     * conversational sentence would be required.
     */
    private function searchText(ProductIntent $intent, ProductDiscoveryInterface $adapter): string
    {
        if ($intent->query !== null) {
            $missing = array_filter(
                $intent->preferences,
                static fn (string $word): bool => ! str_contains(
                    IntentLexiconService::normalize((string) $intent->query),
                    IntentLexiconService::normalize($word),
                ),
            );

            return trim($intent->query . ' ' . implode(' ', $missing));
        }

        if ($adapter->supportsVectors()) {
            return $intent->sentence;
        }

        $keywords = $this->keywordText($intent->sentence);

        // Normalization emptied it: search the original words rather than browse.
        return $keywords !== '' ? $keywords : $intent->sentence;
    }

    /**
     * @return list<string>
     */
    private function optionalWords(ProductIntent $intent, string $text): array
    {
        $normalizedText = IntentLexiconService::normalize($text);
        $words = [];

        foreach ($intent->preferences as $preference) {
            foreach (preg_split('/\s+/u', trim($preference), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                if (str_contains($normalizedText, IntentLexiconService::normalize($word))) {
                    $words[] = $word;
                }
            }
        }

        return array_values(array_unique($words));
    }

    /**
     * An alternative that drops an identity term is how "black phone case"
     * becomes "black", so only phrasings that keep every query word survive.
     *
     * @return list<string>
     */
    private function alternatives(ProductIntent $intent): array
    {
        if ($intent->query === null) {
            return [];
        }

        $identity = $this->words($intent->query);
        $kept = [];

        foreach (array_slice($intent->alternativeQueries, 0, self::MAX_ALTERNATIVES) as $alternative) {
            $words = $this->words($alternative);

            if ($words !== [] && array_diff($identity, $words) === []) {
                $kept[] = trim($alternative);
            }
        }

        return $kept;
    }

    /**
     * @return list<EqualsConstraint|InConstraint|RangeConstraint>
     */
    private function constraints(ProductIntent $intent, ProductDiscoveryInterface $adapter): array
    {
        $constraints = [new EqualsConstraint('apps_id', $this->app->getId())];

        if (! ProductDiscoverySettings::crossCompanyVariants($this->app)) {
            $constraints[] = new EqualsConstraint('companies_id', $this->company->getId());
        }

        if ($intent->inStockOnly) {
            $constraints[] = new EqualsConstraint('in_stock', true);
        }

        $rangeReady = $this->rangeFiltersReady();

        array_push($constraints, ...$this->priceConstraints($intent, $rangeReady));

        if ($intent->audience !== null && $adapter->canFilterOn(SearchFieldEnum::AUDIENCE->value)) {
            $constraints[] = new InConstraint(
                SearchFieldEnum::AUDIENCE->value,
                array_map(
                    static fn (AudienceEnum $audience): string => $audience->value,
                    [$intent->audience, ...AudienceEnum::alwaysIncluded()],
                ),
            );
        }

        // Before the migration these fields don't exist and would empty the pool;
        // the DB recheck still enforces the bound either way.
        if ($rangeReady) {
            foreach ($intent->numericFilters as $filter) {
                if ($filter->max !== null) {
                    $constraints[] = new RangeConstraint(NumericAttributes::minField($filter->field), max: $filter->max);
                }

                if ($filter->min !== null) {
                    $constraints[] = new RangeConstraint(NumericAttributes::maxField($filter->field), min: $filter->min);
                }
            }
        }

        return $constraints;
    }

    /**
     * The index price is the cheapest variant. A floor on it drops a product whose
     * $35k variant answers a $30k–$40k request, so once the range fields exist the
     * bounds test overlap: the floor against price_max, the ceiling against
     * price_min. 0 means "price unknown" and survives a floor, flagged unavailable.
     *
     * @return list<RangeConstraint>
     */
    private function priceConstraints(ProductIntent $intent, bool $rangeReady): array
    {
        $constraints = [];

        if ($intent->minPrice !== null) {
            $constraints[] = new RangeConstraint(
                $rangeReady ? 'price_max' : 'price',
                min: $intent->minPrice,
                alsoAllow: 0.0,
            );
        }

        if ($intent->maxPrice !== null) {
            $constraints[] = new RangeConstraint($rangeReady ? 'price_min' : 'price', max: $intent->maxPrice);
        }

        return $constraints;
    }

    private function keywordText(string $sentence): string
    {
        $lexicon = new IntentLexiconService($this->app);
        $normalized = IntentLexiconService::normalize($sentence);

        $buckets = [
            ...self::LEXICON_BUCKETS,
            ...array_map(static fn (AudienceEnum $audience): string => $audience->lexiconBucket(), AudienceEnum::matchable()),
        ];

        foreach ($buckets as $bucket) {
            $pattern = $lexicon->patternFor($bucket);

            if ($pattern !== null) {
                $normalized = (string) preg_replace('/\b' . $pattern . '\b/u', ' ', $normalized);
            }
        }

        // Amounts the intent already turned into a price filter.
        $suffix = Number::multiplierPattern();
        $normalized = (string) preg_replace('/\$\s*\d[\d.,]*\s*(?:' . $suffix . ')?\b|\b\d[\d.,]*\s*(?:' . $suffix . ')\b/u', ' ', $normalized);

        $stopWords = new SearchTermTokenizerService($this->app)->stopWords();
        $kept = [];

        foreach (preg_split('/[^\p{L}\p{N}\-_]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $isIdentifier = preg_match('/\d/', $token) === 1 && mb_strlen($token) >= 2;

            if ($isIdentifier || (mb_strlen($token) >= 2 && ! in_array($token, $stopWords, true) && ! is_numeric($token))) {
                $kept[] = $token;
            }
        }

        return implode(' ', $kept);
    }

    /**
     * @return list<string>
     */
    private function words(string $text): array
    {
        return array_values(array_unique(
            preg_split('/[^\p{L}\p{N}]+/u', IntentLexiconService::normalize($text), -1, PREG_SPLIT_NO_EMPTY) ?: []
        ));
    }
}
