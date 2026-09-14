<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Inventory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HandlesToolRequest;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\ListsCatalogReferenceData;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

#[AgentTool(name: 'Attribute Search', category: 'inventory')]
class AttributeSearchTool implements KanvasToolInterface
{
    use HandlesToolRequest;
    use HasKanvasContext;
    use ListsCatalogReferenceData;

    public function name(): string
    {
        return 'attribute_search';
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Search the product attributes this company can use — the spec fields like Colour, Size or '
            . 'Material — and see which values each one allows. Use this before set_product_attributes or '
            . 'set_variant_attributes to reuse an existing attribute name and its allowed values rather than '
            . 'inventing a new near-duplicate. A company can have thousands, so search by keyword.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        return (string) json_encode(
            $this->listCatalogAttributes(
                $this->nullableString($request, 'keyword'),
                $this->nullableInt($request, 'limit'),
            ),
            JSON_PRETTY_PRINT
        );
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'keyword' => $schema
                ->string()
                ->description('Text to match against the attribute name. Leave empty to see the first page.'),
            'limit' => $schema
                ->integer()
                ->description('How many to return. Defaults to 25, maximum 100.'),
        ];
    }
}
