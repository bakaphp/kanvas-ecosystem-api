<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Discovery;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Illuminate\Support\Facades\Cache;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Recommendations\DataTransferObject\ProductIntent;
use Kanvas\Inventory\Recommendations\Enums\AudienceEnum;
use Kanvas\Inventory\Recommendations\Exceptions\InvalidDiscoveryInputException;
use Kanvas\Inventory\Recommendations\Services\IntentLexiconService;
use Kanvas\Inventory\Recommendations\Services\ProductDiscoverySettings;

/**
 * Structured discovery input — the agent's tool call or a storefront's filter
 * UI — validated into a ProductIntent. Shared by every entry point so the
 * Laravel tool, the Neuron tool and the GraphQL query reject the same things in
 * the same words.
 *
 * Hard constraints are rejected when invalid; soft input (preferences,
 * alternative phrasings) is trimmed and capped, never an error.
 */
class DiscoveryInputParser
{
    /** What every entry point tells the model when nothing passes the hard limits. */
    public const string NO_MATCH_GUIDANCE = 'No products match every stated limit. Do not retry with the same arguments. '
        . 'Tell the shopper plainly and offer to relax one limit (budget, mileage, category) rather than '
        . 'presenting products that break it.';

    private const int MAX_PREFERENCES = 5;
    private const int MAX_LISTED_CATEGORIES = 30;
    private const int CATEGORY_CACHE_TTL = 600;

    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
    ) {
    }

    /**
     * @param array<string, mixed> $input request, search_query, preferences, min_price, max_price,
     *                                    category, audience, numeric_filters, alternative_queries
     *
     * @throws InvalidDiscoveryInputException
     */
    public function parse(array $input): ProductIntent
    {
        $request = Str::trimToNull((string) ($input['request'] ?? ''));
        $query = Str::trimToNull((string) ($input['search_query'] ?? ''));

        if ($request === null && $query === null) {
            throw new InvalidDiscoveryInputException('request', 'missing — pass the shopper\'s words as `request`');
        }

        [$minPrice, $maxPrice] = $this->price($input);

        return ProductIntent::fromSentence($request ?? $query, new IntentLexiconService($this->app))
            ->withOverrides(
                query: $query,
                preferences: $this->strings($input['preferences'] ?? [], self::MAX_PREFERENCES),
                alternativeQueries: $this->strings($input['alternative_queries'] ?? [], DiscoveryQueryBuilder::MAX_ALTERNATIVES),
                minPrice: $minPrice,
                maxPrice: $maxPrice,
                audience: $this->audience($input['audience'] ?? null),
                category: $this->category($input['category'] ?? null),
                numericFilters: $this->numericFilters($input['numeric_filters'] ?? []),
            );
    }

    /**
     * @return array{0: float|null, 1: float|null}
     */
    private function price(array $input): array
    {
        $min = $this->number($input['min_price'] ?? null, 'min_price');
        $max = $this->number($input['max_price'] ?? null, 'max_price');

        if ($min !== null && $max !== null && $min > $max) {
            throw new InvalidDiscoveryInputException('price', 'contradictory_range — min_price is above max_price');
        }

        return [$min, $max];
    }

    private function audience(mixed $value): ?AudienceEnum
    {
        if ($value === null || $value === '') {
            return null;
        }

        $matchable = AudienceEnum::matchable();
        $audience = AudienceEnum::tryFrom(mb_strtolower(trim((string) $value)));

        if ($audience === null || ! in_array($audience, $matchable, true)) {
            throw new InvalidDiscoveryInputException(
                'audience',
                'unknown_value',
                array_map(static fn (AudienceEnum $case): string => $case->value, $matchable),
            );
        }

        return $audience;
    }

    /**
     * Matched case- and accent-insensitively against the tenant's own categories;
     * returns the stored name so the database recheck compares like with like.
     */
    private function category(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $wanted = IntentLexiconService::normalize((string) $value);
        $names = $this->categoryNames();

        foreach ($names as $name) {
            if (IntentLexiconService::normalize($name) === $wanted) {
                return $name;
            }
        }

        throw new InvalidDiscoveryInputException(
            'category',
            'unknown_category',
            array_slice($names, 0, self::MAX_LISTED_CATEGORIES),
        );
    }

    /**
     * Cached because an agent passes a category on most constrained searches; a category created
     * in the last ten minutes is rejected as unknown until the entry expires.
     *
     * @return list<string>
     */
    private function categoryNames(): array
    {
        $crossCompany = ProductDiscoverySettings::crossCompanyVariants($this->app);
        $key = 'product-discovery:categories:' . $this->app->getId() . ':' . ($crossCompany ? 'all' : $this->company->getId());

        return Cache::remember($key, self::CATEGORY_CACHE_TTL, function () use ($crossCompany): array {
            $query = Categories::query()
                ->where('apps_id', $this->app->getId())
                ->where('is_deleted', 0);

            if (! $crossCompany) {
                $query->where('companies_id', $this->company->getId());
            }

            // select() replaces the model's $withCount subquery, a product count per category row.
            return $query->select('name')
                ->orderBy('name')
                ->pluck('name')
                ->map(static fn (mixed $name): string => trim((string) $name))
                ->filter(static fn (string $name): bool => $name !== '')
                ->unique()
                ->values()
                ->all();
        });
    }

    /**
     * @return list<NumericFilter>
     */
    private function numericFilters(mixed $value): array
    {
        if (! is_array($value) || $value === []) {
            return [];
        }

        $attributes = NumericAttributes::fromApp($this->app);
        $filters = [];

        foreach ($value as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $field = mb_strtolower(trim((string) ($raw['field'] ?? '')));

            if (! $attributes->has($field)) {
                throw new InvalidDiscoveryInputException($field, 'unknown_field', $attributes->describe());
            }

            $unit = Str::trimToNull((string) ($raw['unit'] ?? ''));
            $min = $this->convert(
                $attributes,
                $field,
                $this->number($raw['min'] ?? null, $field . '.min'),
                $unit
            );
            $max = $this->convert(
                $attributes,
                $field,
                $this->number($raw['max'] ?? null, $field . '.max'),
                $unit
            );

            if ($min === null && $max === null) {
                continue;
            }

            if ($min !== null && $max !== null && $min > $max) {
                throw new InvalidDiscoveryInputException($field, 'contradictory_range — min is above max');
            }

            $filters[] = new NumericFilter($field, $min, $max);
        }

        return $filters;
    }

    private function convert(
        NumericAttributes $attributes,
        string $field,
        ?float $value,
        ?string $unit
    ): ?float {
        if ($value === null) {
            return null;
        }

        $converted = $attributes->toCanonical($field, $value, $unit);

        if ($converted === null) {
            throw new InvalidDiscoveryInputException(
                $field,
                sprintf('invalid_unit — "%s" can\'t convert to %s', (string) $unit, $attributes->unitFor($field) ?? 'this field'),
                $attributes->describe(),
            );
        }

        return $converted;
    }

    private function number(mixed $value, string $field): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || (float) $value < 0) {
            throw new InvalidDiscoveryInputException($field, 'invalid_value — expected a non-negative number');
        }

        return (float) $value;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value, int $max): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $strings = array_values(array_filter(
            array_map(static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '', $value),
            static fn (string $item): bool => $item !== '',
        ));

        return array_slice(array_values(array_unique($strings)), 0, $max);
    }
}
