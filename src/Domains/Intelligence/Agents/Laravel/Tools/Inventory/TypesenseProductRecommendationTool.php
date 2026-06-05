<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Inventory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
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

/**
 * Typesense-only recommendation lookup driven by Typesense Natural Language
 * Search. The customer's free-form sentence is handed to Typesense, whose
 * configured LLM (e.g. Gemini) turns it into filter_by / sort_by against the
 * products collection — so all the intent parsing ("cosas caras" → sort price
 * desc, "para mi hermano" → audience, "menos de 50" → price filter) happens
 * server-side. We keep only the tenant-safe DB hydration + the recommendation
 * JSON shape the agent's structured-output schema expects.
 *
 * Intentionally self-contained and separate from ProductRecommendationLookupTool
 * (SQL / Algolia hybrid): the two matching strategies are never mixed. Wire this
 * tool to agents whose tenant runs on Typesense with an NL model configured.
 */
#[AgentTool(name: 'Typesense Product Recommendation')]
class TypesenseProductRecommendationTool implements KanvasToolInterface
{
    use HasKanvasContext;

    private const string DEFAULT_NL_MODEL_ID = 'gemini-model';

    #[Override]
    public function description(): Stringable|string
    {
        return 'Recommend products from a free-form, conversational request using Typesense '
            . 'Natural Language Search. Pass the customer message VERBATIM (in their language) — '
            . 'Typesense\'s LLM parses intent (recipient, occasion, budget, "expensive/cheap") into '
            . 'filters and sorting on its own, so you do NOT need to pre-extract keywords or price. '
            . 'Returns each product with its files, categories and full variant data (price, stock, '
            . 'channel.is_available). Out-of-stock / unpriced products are still returned, flagged '
            . 'is_available=false. Only usable when the tenant is on Typesense with an NL model set.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $query = trim((string) $request->string('query'));
        $limit = min(max($request->integer('limit', 8), 1), 20);

        if ($query === '') {
            return 'Provide the customer request as `query`.';
        }

        if (! $this->typesenseConfigured()) {
            return 'Typesense natural-language search is not enabled for this app. '
                . 'Use the standard product recommendation tool instead.';
        }

        // Pull a wider pool than we return so products that map to zero variants
        // can be dropped without starving the result set.
        $ids = $this->naturalLanguageProductIds($query, min($limit * 3, 60));

        if ($ids === []) {
            return 'No products found matching the request.';
        }

        // Preserve Typesense's ranking (it already applied the LLM-generated
        // sort_by, e.g. price desc for "cosas caras").
        $position = [];
        foreach (array_values($ids) as $i => $id) {
            $position[(int) $id] = $i;
        }

        $defaultChannelId = $this->resolveDefaultChannelId();

        $results = $this->baseQuery()
            ->whereIn('id', array_keys($position))
            ->get()
            ->sortBy(fn (Products $product) => $position[$product->getId()] ?? PHP_INT_MAX)
            ->map(fn (Products $product) => $this->mapProduct($product, $defaultChannelId))
            ->filter()
            ->take($limit)
            ->values();

        if ($results->isEmpty()) {
            return 'No products found matching the request.';
        }

        return $results->toJson(JSON_PRETTY_PRINT);
    }

    /**
     * @return array<int, int|string>
     */
    private function naturalLanguageProductIds(string $query, int $poolSize): array
    {
        try {
            return Products::search($query)
                ->options([
                    'query_by' => 'name,description,categories_flat',
                    'nl_query' => true,
                    'nl_model_id' => $this->nlModelId(),
                ])
                ->take($poolSize)
                ->keys()
                ->all();
        } catch (Throwable) {
            // Unreachable engine / misconfigured NL model — surface as no-results
            // rather than throwing into the agent loop.
            return [];
        }
    }

    private function typesenseConfigured(): bool
    {
        // Mirror SearchEngineResolver precedence: the model-specific override
        // (products_search_engine) wins over the app default (search_engine),
        // which wins over the global scout.driver. Getting this order wrong would
        // make the tool disagree with how Products::search actually routes.
        $engine = $this->app->get('products_search_engine')
            ?? $this->app->get('search_engine')
            ?? config('scout.driver');

        return $engine === 'typesense';
    }

    private function nlModelId(): string
    {
        $modelId = $this->app->get('typesense_nl_model_id');

        return is_string($modelId) && $modelId !== '' ? $modelId : self::DEFAULT_NL_MODEL_ID;
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

    private function resolveDefaultChannelId(): ?int
    {
        try {
            return Channels::getDefault($this->company, $this->app)->getId();
        } catch (Throwable) {
            return null;
        }
    }

    private function mapProduct(Products $product, ?int $defaultChannelId): ?array
    {
        $variants = $product->variants
            ->map(fn (Variants $variant) => $this->mapVariant($variant, $defaultChannelId))
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

    private function resolveChannel(Variants $variant, ?int $defaultChannelId): array
    {
        $quantity = $variant->getTotalQuantity();

        if ($defaultChannelId === null) {
            return $this->emptyChannel($quantity);
        }

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

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema
                ->string()
                ->description('The customer\'s request, VERBATIM and in their original language '
                    . '(e.g. "un regalo para mi hermano mayor que le gustan las cosas caras"). '
                    . 'Do not pre-parse it — Typesense\'s LLM extracts budget, recipient and intent.')
                ->required(),
            'limit' => $schema
                ->integer()
                ->description('Maximum number of products to return (1-20). Defaults to 8.')
                ->default(8),
        ];
    }
}
