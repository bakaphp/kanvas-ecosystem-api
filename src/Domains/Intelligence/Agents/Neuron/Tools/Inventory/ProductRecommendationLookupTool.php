<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\Concerns\RespondsWithNoInventoryMatches;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Inventory\Recommendations\Actions\RecommendProductsAction;
use Kanvas\Inventory\Recommendations\Discovery\DiscoveryInputParser;
use Kanvas\Inventory\Recommendations\Discovery\NumericAttributes;
use Kanvas\Inventory\Recommendations\Exceptions\InvalidDiscoveryInputException;
use Kanvas\Souk\Services\StorefrontProductUrlService;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * The Neuron twin of the Laravel tool of the same name, for Neuron-runtime
 * agents (dealer and shopping agents answering in Slack, SMS, WhatsApp). Both
 * go through DiscoveryInputParser and RecommendProductsAction, so they reject
 * and return exactly the same things.
 */
#[AgentTool(name: 'Product Recommendation Lookup', category: 'inventory')]
class ProductRecommendationLookupTool extends Tool
{
    use HasKanvasContext;
    use RespondsWithNoInventoryMatches;

    private const int DEFAULT_LIMIT = 8;

    protected string $name = 'product_recommendation_lookup';

    protected ?string $description = 'Find products for a shopper described in natural language. ONE call per '
        . 'request: pass the shopper\'s words verbatim as `request`, and in the same call add what you '
        . 'understood — a short `search_query` in catalog terms ("SUV", "perfume mujer"), descriptive '
        . '`preferences` that may boost but must not be required ("black"), a budget as `min_price` / '
        . '`max_price`, a `category`, an `audience`, and numeric limits as `numeric_filters` (e.g. '
        . 'field "mileage", max 100000, unit "mi"). Only send a filter the shopper actually stated. An '
        . 'invalid filter comes back as {"error": "invalid_filter", "allowed": [...]}: fix that argument '
        . 'and call again. Returns products in the same shape as inventory_search (id, name, url, '
        . 'total_stock, variants with id, sku, stock and price), but only the variants that satisfy '
        . 'every limit — recommend only those, and use their `variants[].id` with variant_detail or '
        . 'any order/cart action. When nothing matches, offer to relax one limit; never present a '
        . 'product that breaks one.';

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'request',
                type: PropertyType::STRING,
                description: 'The shopper\'s request in their own words, verbatim and in their own language.',
                required: true,
            ),
            new ToolProperty(
                name: 'search_query',
                type: PropertyType::STRING,
                description: 'The kind of product only, in the catalog\'s own terms, e.g. "SUV", "phone case". No budget, colour or recipient words.',
                required: false,
            ),
            new ArrayProperty(
                name: 'preferences',
                description: 'Descriptive words that may boost but must not be required, e.g. ["black", "leather"].',
                required: false,
                items: new ToolProperty(name: 'preference', type: PropertyType::STRING, description: 'One descriptive word or short phrase.'),
            ),
            new ToolProperty(
                name: 'min_price',
                type: PropertyType::NUMBER,
                description: 'Lowest price the shopper stated, as a plain number (20000, not "20k").',
                required: false,
            ),
            new ToolProperty(
                name: 'max_price',
                type: PropertyType::NUMBER,
                description: 'Highest price the shopper stated, as a plain number.',
                required: false,
            ),
            new ToolProperty(
                name: 'category',
                type: PropertyType::STRING,
                description: 'A category the shopper named, as the catalog names it.',
                required: false,
            ),
            new ToolProperty(
                name: 'audience',
                type: PropertyType::STRING,
                description: 'Who the product is for, when stated: male, female, kids, baby, teen or senior.',
                required: false,
            ),
            new ArrayProperty(
                name: 'numeric_filters',
                description: $this->numericFiltersDescription(),
                required: false,
                items: new ObjectProperty(
                    name: 'numeric_filter',
                    description: 'One numeric limit.',
                    properties: [
                        new ToolProperty(name: 'field', type: PropertyType::STRING, description: 'A configured numeric field, e.g. "mileage".', required: true),
                        new ToolProperty(name: 'min', type: PropertyType::NUMBER, description: 'Lowest allowed value.', required: false),
                        new ToolProperty(name: 'max', type: PropertyType::NUMBER, description: 'Highest allowed value.', required: false),
                        new ToolProperty(name: 'unit', type: PropertyType::STRING, description: 'Unit of min/max when the shopper gave one, e.g. "mi" or "km".', required: false),
                    ],
                ),
            ),
            new ArrayProperty(
                name: 'alternative_queries',
                description: 'Up to 2 other phrasings of search_query that keep the same product words.',
                required: false,
                items: new ToolProperty(name: 'alternative_query', type: PropertyType::STRING, description: 'One alternative phrasing.'),
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Maximum number of products to return (1-24). Defaults to 8.',
                required: false,
            ),
        ];
    }

    /**
     * @param list<string>|null $preferences
     * @param list<array<string, mixed>>|null $numeric_filters
     * @param list<string>|null $alternative_queries
     */
    public function __invoke(
        string $request,
        ?string $search_query = null,
        ?array $preferences = null,
        float|int|null $min_price = null,
        float|int|null $max_price = null,
        ?string $category = null,
        ?string $audience = null,
        ?array $numeric_filters = null,
        ?array $alternative_queries = null,
        ?int $limit = null,
    ): array {
        try {
            $intent = new DiscoveryInputParser($this->app, $this->company)->parse(compact(
                'request',
                'search_query',
                'preferences',
                'min_price',
                'max_price',
                'category',
                'audience',
                'numeric_filters',
                'alternative_queries',
            ));
        } catch (InvalidDiscoveryInputException $e) {
            return $e->toArray();
        }

        $results = new RecommendProductsAction($this->app, $this->company)
            ->executeIntent($intent, $limit ?? self::DEFAULT_LIMIT);

        if ($results === []) {
            return [...$this->noMatchesResponse($request), 'relax' => DiscoveryInputParser::NO_MATCH_GUIDANCE];
        }

        $storefront = new StorefrontProductUrlService($this->company, $this->app);

        return array_map(fn (array $result): array => $this->inventoryShape($result, $storefront), $results);
    }

    /**
     * The inventory_search shape, so a prompt, flow or follow-up action written against that
     * tool works on these results unchanged. Variant availability is added on top: here a
     * product can carry an unavailable variant flagged rather than dropped.
     *
     * @param array{product: array, variants: array} $result
     */
    private function inventoryShape(array $result, StorefrontProductUrlService $storefront): array
    {
        $variants = array_map(
            static fn (array $variant): array => [
                'id' => $variant['id'],
                'name' => $variant['name'],
                'sku' => $variant['sku'],
                'stock' => (int) ($variant['channel']['quantity'] ?? 0),
                'price' => $variant['channel']['price'] ?? null,
                'discounted_price' => $variant['channel']['discounted_price'] ?? null,
                'is_available' => (bool) ($variant['channel']['is_available'] ?? false),
            ],
            $result['variants'],
        );

        return [
            'id' => $result['product']['id'],
            'name' => $result['product']['name'],
            'slug' => $result['product']['slug'],
            'url' => $storefront->urlFor((int) $result['product']['id'], (string) $result['product']['slug']),
            'is_published' => true,
            'is_available' => in_array(true, array_column($variants, 'is_available'), true),
            'total_stock' => array_sum(array_column($variants, 'stock')),
            'variants' => $variants,
        ];
    }

    /**
     * Built per tenant so the model sees the fields this catalog actually has.
     * A parameter description, not the tool `$description`, which must stay a
     * literal for the catalog sync.
     */
    private function numericFiltersDescription(): string
    {
        return isset($this->app)
            ? NumericAttributes::fromApp($this->app)->agentGuidance()
            : 'Numeric limits the shopper stated, on the catalog\'s configured numeric fields.';
    }
}
