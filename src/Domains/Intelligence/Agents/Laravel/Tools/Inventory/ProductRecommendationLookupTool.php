<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Inventory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Enums\ConfigurationEnum as SoukConfigurationEnum;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;
use Throwable;

#[AgentTool(name: 'Product Recommendation Lookup')]
class ProductRecommendationLookupTool implements KanvasToolInterface
{
    use HasKanvasContext;

    /**
     * Low-signal words dropped from the keyword before matching so a phrase
     * like "regalo para mi esposo" doesn't search the literal string.
     *
     * @var list<string>
     */
    private const array STOP_WORDS = [
        'para', 'mi', 'de', 'un', 'una', 'el', 'la', 'los', 'las', 'y', 'o',
        'con', 'que', 'the', 'for', 'my', 'a', 'an', 'to', 'of', 'with', 'and', 'or',
    ];

    /**
     * Recipient-gender synonyms (ES/EN) used to bias ranking toward products
     * whose text/categories/attributes target that gender.
     *
     * @var array<string, list<string>>
     */
    private const array GENDER_SYNONYMS = [
        'male' => ['hombre', 'men', 'man', 'male', 'masculino', 'caballero', 'él', 'esposo', 'novio', 'papá'],
        'female' => ['mujer', 'women', 'woman', 'female', 'femenino', 'dama', 'ella', 'esposa', 'novia', 'mamá'],
        'unisex' => ['unisex'],
    ];

    #[Override]
    public function description(): Stringable|string
    {
        return 'Look up rich inventory data shaped for product recommendations. '
            . 'Returns each product with its files, categories, and full variant data '
            . '(id, slug, sku, attributes, channel pricing, files), ranked by how well it '
            . 'matches the request. The keyword is tokenized (each word matched and scored '
            . 'across name, description and category), so multi-word interests work. '
            . 'Pass recipient_gender / occasion / age and categories to sharpen ranking for '
            . 'gift-style requests (e.g. "un regalo para mi esposo de 35"). '
            . 'With no keyword it returns the top-rated published products.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $limit = min(max($request->integer('limit', 5), 1), 20);
        $onlyInStock = $request->boolean('only_in_stock', true);
        $minPrice = $request->has('min_price') ? $request->float('min_price') : null;
        $maxPrice = $request->has('max_price') ? $request->float('max_price') : null;

        // Primary terms drive matching+ranking; context terms (recipient) only
        // boost ranking — they never make a product match on their own.
        $primaryTerms = array_values(array_unique(array_merge(
            $this->tokenize((string) $request->string('keyword')),
            $this->categoryTerms($request),
        )));
        $contextTerms = $this->contextTerms($request);

        // When ranking, pull a wider pool than we return so the in-PHP score
        // (which also reads categories + variant attributes) can reorder it.
        $hasSignal = $primaryTerms !== [] || $contextTerms !== [];
        $poolSize = ($hasSignal || $minPrice !== null || $maxPrice !== null)
            ? min($limit * 4, 80)
            : $limit;

        // Candidate matching is pluggable: the tenant's Scout engine (Algolia /
        // Typesense / Meilisearch) when one is configured, else the SQL term
        // filter. Either way the products are re-scoped to this tenant on
        // hydration in baseQuery(), so a mis-scoped engine can't leak rows.
        [$products, $engineUsed, $relevanceById] = $this->fetchCandidates(
            $primaryTerms,
            $contextTerms,
            $poolSize,
        );

        if ($products->isEmpty()) {
            return $primaryTerms === [] && $contextTerms === []
                ? 'No published products available to recommend.'
                : 'No products found matching the request.';
        }

        $defaultChannelId = $this->resolveDefaultChannelId();

        $results = $products
            ->map(function (Products $product) use (
                $onlyInStock,
                $minPrice,
                $maxPrice,
                $defaultChannelId,
                $primaryTerms,
                $contextTerms,
                $engineUsed,
                $relevanceById,
            ): ?array {
                $mapped = $this->mapProduct($product, $minPrice, $maxPrice, $defaultChannelId);

                if ($mapped === null) {
                    return null;
                }

                $haystack = $this->buildHaystack($product, $mapped['variants']);
                $primaryScore = $this->scoreText($haystack, $primaryTerms);

                // SQL path only: the LIKE OR can match a sibling column, so drop
                // products that don't actually contain a primary term. The engine
                // already ranked by relevance, so its hits are trusted.
                if (! $engineUsed && $primaryTerms !== [] && $primaryScore === 0) {
                    return null;
                }

                // only_in_stock is a soft preference: when set, buyable products
                // (priced + in stock) float to the top while out-of-stock /
                // unpriced ones still appear below, flagged is_available=false.
                $availabilityBonus = ($onlyInStock && $this->hasAvailableVariant($mapped['variants']))
                    ? 1000.0
                    : 0.0;

                // Engine relevance (pool position) is the dominant signal when an
                // engine ran; the term/context scores still nudge recipient fit.
                $engineRelevance = (float) ($relevanceById[$product->getId()] ?? 0);

                $mapped['_rank'] = $availabilityBonus
                    + $engineRelevance * 5.0
                    + (float) (($primaryScore * 2 + $this->scoreText($haystack, $contextTerms)) * 10)
                    + min((float) ($product->rating ?? 0), 9.9);

                return $mapped;
            })
            ->filter()
            ->sortByDesc('_rank')
            ->take($limit)
            ->map(function (array $result): array {
                unset($result['_rank']);

                return $result;
            })
            ->values();

        if ($results->isEmpty()) {
            $budget = $this->describeBudget($minPrice, $maxPrice);

            return "No in-stock products found matching the request{$budget}.";
        }

        return $results->toJson(JSON_PRETTY_PRINT);
    }

    /**
     * Resolve the candidate product pool. Prefers the tenant's Scout engine
     * (Algolia / Typesense / Meilisearch) for matching when one is configured,
     * otherwise falls back to the SQL term filter. Returns the (re-scoped)
     * products, whether the engine ran, and an id → relevance-rank map.
     *
     * @param list<string> $primaryTerms
     * @param list<string> $contextTerms
     *
     * @return array{0: Collection<int, Products>, 1: bool, 2: array<int, int>}
     */
    private function fetchCandidates(array $primaryTerms, array $contextTerms, int $poolSize): array
    {
        $searchString = trim(implode(' ', array_unique(array_merge($primaryTerms, $contextTerms))));

        if ($searchString !== '' && $this->searchEngineConfigured()) {
            $ids = $this->searchEngineProductIds($searchString, $poolSize);

            if ($ids !== []) {
                $relevance = [];
                foreach (array_values($ids) as $position => $id) {
                    $relevance[(int) $id] = $poolSize - $position;
                }

                $products = $this->baseQuery()
                    ->whereIn('id', array_keys($relevance))
                    ->get();

                if ($products->isNotEmpty()) {
                    return [$products, true, $relevance];
                }
            }
        }

        return [$this->fetchViaSql($primaryTerms, $contextTerms, $poolSize), false, []];
    }

    /**
     * @return array<int, int|string>
     */
    private function searchEngineProductIds(string $searchString, int $poolSize): array
    {
        try {
            return Products::search($searchString)
                ->where('apps_id', $this->app->getId())
                ->take($poolSize)
                ->keys()
                ->all();
        } catch (Throwable) {
            // Misconfigured / unreachable engine — fall back to SQL.
            return [];
        }
    }

    /**
     * @param list<string> $primaryTerms
     * @param list<string> $contextTerms
     *
     * @return Collection<int, Products>
     */
    private function fetchViaSql(array $primaryTerms, array $contextTerms, int $poolSize): Collection
    {
        $query = $this->baseQuery();

        if ($primaryTerms !== []) {
            $this->applyTermFilter($query, $primaryTerms);
        } elseif ($contextTerms !== []) {
            $this->applyTermFilter($query, $contextTerms);
        } else {
            // No signal at all → recommend the best-rated products.
            $query->orderByDesc('rating');
        }

        return $query->limit($poolSize)->get();
    }

    private function baseQuery(): Builder
    {
        $query = Products::fromApp($this->app)
            ->notDeleted()
            ->where('is_published', 1)
            ->with(['categories', 'variants.variantChannels.productVariantWarehouse']);

        if (! (bool) $this->app->get(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value)) {
            $query->fromCompany($this->company);
        }

        return $query;
    }

    private function searchEngineConfigured(): bool
    {
        $engine = $this->app->get('search_engine')
            ?? $this->app->get('products_search_engine')
            ?? config('scout.driver');

        return is_string($engine)
            && $engine !== ''
            && ! in_array($engine, ['null', 'database', 'collection'], true);
    }

    /**
     * @param array<int, string> $terms
     */
    private function applyTermFilter(Builder $query, array $terms): void
    {
        $query->where(function (Builder $outer) use ($terms): void {
            foreach ($terms as $term) {
                $like = '%' . $term . '%';
                $outer->orWhere('name', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('short_description', 'like', $like)
                    ->orWhereHas('categories', fn (Builder $c) => $c->where('name', 'like', $like));
            }
        });
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $value): array
    {
        $value = mb_strtolower(trim($value));

        if ($value === '') {
            return [];
        }

        $tokens = preg_split('/[\s,]+/u', $value) ?: [];

        return array_values(array_filter(
            $tokens,
            fn (string $t): bool => mb_strlen($t) >= 2 && ! in_array($t, self::STOP_WORDS, true),
        ));
    }

    /**
     * @return list<string>
     */
    private function categoryTerms(Request $request): array
    {
        $terms = [];

        foreach ($request->array('categories') as $category) {
            $terms = array_merge($terms, $this->tokenize((string) $category));
        }

        return $terms;
    }

    /**
     * Recipient signals → ranking-only terms. Includes the raw value plus a
     * small ES/EN synonym set for gender, and a coarse age bucket.
     *
     * @return list<string>
     */
    private function contextTerms(Request $request): array
    {
        $terms = [];

        $gender = mb_strtolower(trim((string) $request->string('recipient_gender')));
        if ($gender !== '') {
            $terms = array_merge($terms, self::GENDER_SYNONYMS[$gender] ?? [$gender]);
        }

        $terms = array_merge($terms, $this->tokenize((string) $request->string('occasion')));

        $age = $request->integer('age', 0);
        if ($age > 0) {
            $terms = array_merge($terms, $this->ageBucketTerms($age));
        }

        return array_values(array_unique($terms));
    }

    /**
     * @return list<string>
     */
    private function ageBucketTerms(int $age): array
    {
        return match (true) {
            $age <= 3 => ['bebé', 'baby', 'infant'],
            $age <= 12 => ['niño', 'kids', 'kid', 'children'],
            $age <= 17 => ['adolescente', 'teen', 'juvenil'],
            default => ['adulto', 'adult'],
        };
    }

    /**
     * @param array<int, array<string, mixed>> $variants
     */
    private function buildHaystack(Products $product, array $variants): string
    {
        $parts = [
            $product->name,
            $product->description,
            (string) $product->short_description,
        ];

        foreach ($product->categories as $category) {
            $parts[] = (string) $category->name;
        }

        foreach ($variants as $variant) {
            $parts[] = (string) ($variant['name'] ?? '');
            foreach ($variant['attributes'] ?? [] as $attribute) {
                $parts[] = (string) ($attribute['name'] ?? '');
                $parts[] = (string) ($attribute['value'] ?? '');
            }
        }

        return mb_strtolower(implode(' ', $parts));
    }

    /**
     * @param array<int, string> $terms
     */
    private function scoreText(string $haystack, array $terms): int
    {
        $score = 0;

        foreach ($terms as $term) {
            if ($term !== '' && mb_stripos($haystack, $term) !== false) {
                $score++;
            }
        }

        return $score;
    }

    private function resolveDefaultChannelId(): ?int
    {
        try {
            return Channels::getDefault($this->company, $this->app)->getId();
        } catch (Throwable) {
            return null;
        }
    }

    private function mapProduct(
        Products $product,
        ?float $minPrice,
        ?float $maxPrice,
        ?int $defaultChannelId,
    ): ?array {
        $variants = $product->variants
            ->map(fn (Variants $variant) => $this->mapVariant($variant, $defaultChannelId))
            ->filter(function (array $v) use ($minPrice, $maxPrice) {
                $price = $v['channel']['price'] ?? null;
                $hasPrice = $price !== null && $price > 0;

                // Stock is NOT a hard gate — out-of-stock and unpriced variants
                // are kept and surfaced (channel.is_available = false) so the
                // caller can show them as currently unavailable and notify.
                // Budget bounds only apply to variants that actually have a price.
                if ($hasPrice && $minPrice !== null && $price < $minPrice) {
                    return false;
                }

                if ($hasPrice && $maxPrice !== null && $price > $maxPrice) {
                    return false;
                }

                return true;
            })
            ->values();

        if ($variants->isEmpty()) {
            return null;
        }

        return [
            'product' => [
                'id' => $product->getId(),
                'slug' => $product->slug,
                'name' => $product->name,
                'files' => $this->mapFiles($product),
                'categories' => $product->categories
                    ->map(fn ($category) => [
                        'id' => $category->getId(),
                        'name' => $category->name,
                    ])
                    ->all(),
            ],
            'variants' => $variants->all(),
        ];
    }

    private function mapVariant(Variants $variant, ?int $defaultChannelId): array
    {
        return [
            'id' => $variant->getId(),
            'slug' => $variant->slug,
            'name' => $variant->name,
            'sku' => $variant->sku,
            'description' => $variant->description,
            'attributes' => array_map(
                fn (array $a) => [
                    'id' => $a['id'] ?? null,
                    'name' => $a['name'] ?? null,
                    'value' => $this->stringifyValue($a['value'] ?? null),
                ],
                $variant->visibleAttributes(),
            ),
            'channel' => $this->resolveChannel($variant, $defaultChannelId),
            'files' => $this->mapFiles($variant),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $variants
     */
    private function hasAvailableVariant(array $variants): bool
    {
        foreach ($variants as $variant) {
            if (($variant['channel']['is_available'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Total stock across warehouses — the same source InventorySearchTool uses.
     * The default channel's productVariantWarehouse link is often unset even
     * when the variant has stock, so relying on it dropped in-stock products.
     */
    private function resolveQuantity(Variants $variant): int
    {
        return $variant->getTotalQuantity();
    }

    private function resolveChannel(Variants $variant, ?int $defaultChannelId): array
    {
        $quantity = $this->resolveQuantity($variant);

        if ($defaultChannelId === null) {
            return $this->emptyChannel($quantity);
        }

        // Reads the eager-loaded variantChannels collection (loaded in handle())
        // so this stays O(1) instead of a query per variant.
        $channelInfo = $variant->variantChannels
            ->firstWhere('channels_id', $defaultChannelId);

        if (! $channelInfo) {
            return $this->emptyChannel($quantity);
        }

        $price = (float) $channelInfo->price;
        $discounted = (float) $channelInfo->discounted_price;

        return [
            'price' => $price,
            'discounted_price' => $discounted,
            'is_on_sale' => $discounted > 0 && $discounted < $price,
            'is_available' => $price > 0 && $quantity > 0,
            'quantity' => $quantity,
        ];
    }

    private function emptyChannel(int $quantity = 0): array
    {
        return [
            'price' => null,
            'discounted_price' => null,
            'is_on_sale' => false,
            'is_available' => false,
            'quantity' => $quantity,
        ];
    }

    private function mapFiles(Products|Variants $entity): array
    {
        return $entity->getFiles()
            ->take(10)
            ->map(fn ($file) => [
                'id' => $file->getId(),
                'url' => $file->url,
                'name' => $file->name,
            ])
            ->values()
            ->all();
    }

    private function stringifyValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value);
    }

    private function describeBudget(?float $minPrice, ?float $maxPrice): string
    {
        if ($minPrice !== null && $maxPrice !== null) {
            return " within budget {$minPrice} - {$maxPrice}";
        }

        if ($minPrice !== null) {
            return " above {$minPrice}";
        }

        if ($maxPrice !== null) {
            return " below {$maxPrice}";
        }

        return '';
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'keyword' => $schema
                ->string()
                ->description('Interest / product keyword(s) in the user\'s language (e.g. "perfume", "reloj cocina"). Tokenized and scored — multiple words are fine. Leave empty to get top-rated products.'),
            'categories' => $schema
                ->array()
                ->items($schema->string())
                ->description('Candidate category names to bias toward (e.g. ["Perfumes", "Relojes"]). Use CategorySearchTool first to discover real names.'),
            'recipient_gender' => $schema
                ->string()
                ->description('Who the gift is for: "male", "female", or "unisex". Derive from the request (e.g. "esposo" → male). Boosts ranking only.'),
            'occasion' => $schema
                ->string()
                ->description('Occasion context, e.g. "cumpleaños", "navidad", "aniversario". Boosts ranking only.'),
            'age' => $schema
                ->integer()
                ->description('Recipient age. Mapped to a coarse bucket (kid/teen/adult) to bias ranking.'),
            'min_price' => $schema
                ->number()
                ->description('Minimum variant price (inclusive). Use the user-provided budget.'),
            'max_price' => $schema
                ->number()
                ->description('Maximum variant price (inclusive). Use the user-provided budget.'),
            'limit' => $schema
                ->integer()
                ->description('Maximum number of products to return (1-20). Defaults to 5.')
                ->default(5),
            'only_in_stock' => $schema
                ->boolean()
                ->description('Soft preference (NOT a hard filter). When true, buyable products '
                    . '(priced + in stock) are ranked first; out-of-stock / unpriced products are '
                    . 'still returned below them, flagged channel.is_available=false. Defaults to true.')
                ->default(true),
        ];
    }
}
