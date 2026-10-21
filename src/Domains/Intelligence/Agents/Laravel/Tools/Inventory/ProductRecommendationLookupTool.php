<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Inventory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Concerns\HasKanvasContext;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Inventory\Recommendations\Actions\RecommendProductsAction;
use Kanvas\Inventory\Recommendations\Discovery\DiscoveryInputParser;
use Kanvas\Inventory\Recommendations\Discovery\NumericAttributes;
use Kanvas\Inventory\Recommendations\Exceptions\InvalidDiscoveryInputException;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

/**
 * A thin pass-through by design: input validation lives in DiscoveryInputParser and matching,
 * tenant scoping and engine selection in RecommendProductsAction, so the storefront endpoint, this
 * tool and its Neuron twin cannot drift apart.
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
        return 'Find products for a shopper described in natural language. ONE call per request: pass '
            . 'the shopper\'s words verbatim as `request`, and in the same call pull out what you '
            . 'understood — a short `search_query` in the catalog\'s own terms ("SUV", "perfume mujer"), '
            . 'descriptive `preferences` that may boost but must not be required ("black", "elegante"), '
            . 'a budget as `min_price`/`max_price`, a `category`, an `audience`, and numeric limits as '
            . '`numeric_filters` (e.g. {field: "mileage", max: 100000, unit: "mi"}). Only send a filter '
            . 'the shopper actually stated. An invalid filter comes back as {"error": "invalid_filter", '
            . '"allowed": [...]}: fix that argument and call again. Returns each product with only the '
            . 'variants that satisfy every limit, each with price, stock and channel.is_available — '
            . 'recommend only those variants. Unavailable products are still returned, flagged '
            . 'is_available=false.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        try {
            // `query` was the verbatim argument before `request`; an agent on a stale prompt still sends it.
            $intent = new DiscoveryInputParser($this->app, $this->company)->parse([
                ...$request->all(),
                'request' => $request->string('request')->toString() ?: $request->string('query')->toString(),
            ]);
        } catch (InvalidDiscoveryInputException $e) {
            return (string) json_encode($e->toArray(), JSON_UNESCAPED_UNICODE);
        }

        $results = new RecommendProductsAction($this->app, $this->company)
            ->executeIntent($intent, $request->integer('limit', self::DEFAULT_LIMIT));

        if ($results === []) {
            return DiscoveryInputParser::NO_MATCH_GUIDANCE;
        }

        return (string) json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {

        return [
            'request' => $schema
                ->string()
                ->description('The shopper\'s request in their own words, verbatim and in their own language.')
                ->required(),
            'search_query' => $schema
                ->string()
                ->description('A short search in the catalog\'s own terms: the kind of product only, e.g. "SUV", "phone case", "perfume". No budget, colour or recipient words here.'),
            'preferences' => $schema
                ->array()
                ->items($schema->string())
                ->description('Descriptive words that may boost but must not be required, e.g. ["black", "leather"].'),
            'min_price' => $schema->number()->description('Lowest price the shopper stated, as a plain number (20000, not "20k").'),
            'max_price' => $schema->number()->description('Highest price the shopper stated, as a plain number.'),
            'category' => $schema->string()->description('A category the shopper named, as the catalog names it.'),
            'audience' => $schema
                ->string()
                ->description('Who the product is for, when stated: male, female, kids, baby, teen or senior.'),
            'numeric_filters' => $schema
                ->array()
                ->items($schema->object([
                    'field' => $schema->string(),
                    'min' => $schema->number(),
                    'max' => $schema->number(),
                    'unit' => $schema->string(),
                ]))
                ->description($this->numericFiltersGuidance()),
            'alternative_queries' => $schema
                ->array()
                ->items($schema->string())
                ->description('Up to 2 other phrasings of search_query that keep the same product words, e.g. ["sport utility vehicle"].'),
            'limit' => $schema
                ->integer()
                ->description('Maximum number of products to return (1-24). Defaults to 8.')
                ->default(self::DEFAULT_LIMIT),
        ];
    }

    /** The schema can be read before a tenant is bound (catalog sync, tests). */
    private function numericFiltersGuidance(): string
    {
        return isset($this->app)
            ? NumericAttributes::fromApp($this->app)->agentGuidance()
            : 'Numeric limits the shopper stated, on the catalog\'s configured numeric fields.';
    }
}
