<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Inventory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Inventory\Recommendations\Actions\RecommendProductsAction;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

/**
 * Conversational entry point to product discovery.
 *
 * A thin pass-through by design: matching, tenant scoping, budget parsing and
 * engine selection all live in RecommendProductsAction, so the storefront
 * endpoint and the agent cannot drift apart. This replaced a pair of tools that
 * each reimplemented the same lookup for a different search backend — the
 * backend is now resolved per tenant behind the action.
 */
#[AgentTool(name: 'Product Recommendation Lookup', category: 'inventory')]
class ProductRecommendationLookupTool implements KanvasToolInterface
{
    use HasKanvasContext;

    private const int DEFAULT_LIMIT = 8;

    public function name(): string
    {
        return 'product_recommendation_lookup';
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Find products for a shopper described in natural language. Pass their request '
            . 'VERBATIM as `query`, in whatever language they used — do NOT pre-extract keywords, '
            . 'gender, age or budget, and do NOT split the request into several calls. '
            . 'The search understands the whole sentence, including budgets ("menos de $50", '
            . '"under 30") which become real price filters. '
            . 'Returns each product with its files, categories and full variant data '
            . '(price, stock, channel.is_available). Out-of-stock and unpriced products are still '
            . 'returned, flagged is_available=false, so they can be shown as unavailable.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $query = trim((string) $request->string('query'));

        if ($query === '') {
            return 'Provide the shopper\'s request as `query`.';
        }

        $results = new RecommendProductsAction($this->app, $this->company)
            ->execute($query, $request->integer('limit', self::DEFAULT_LIMIT));

        if ($results === []) {
            return 'No products found matching the request. Try a different wording or a broader '
                . 'product type before giving up.';
        }

        return (string) json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema
                ->string()
                ->description('The shopper\'s request in their own words, verbatim and in their own language — e.g. "un regalo para mi hermano de 25 que le gustan las cosas caras" or "a gift for a coworker who likes coffee, under $30".'),
            'limit' => $schema
                ->integer()
                ->description('Maximum number of products to return (1-24). Defaults to 8.')
                ->default(self::DEFAULT_LIMIT),
        ];
    }
}
