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

#[AgentTool(name: 'Category Search', category: 'inventory')]
class CategorySearchTool implements KanvasToolInterface
{
    use HandlesToolRequest;
    use HasKanvasContext;
    use ListsCatalogReferenceData;

    public function name(): string
    {
        return 'category_search';
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Search the product categories of this company by name, and get their ids. Use this to find the '
            . 'category_ids to pass to set_product_categories. A company can have thousands of categories, so '
            . 'always search by keyword rather than listing them all.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        return (string) json_encode(
            $this->listCatalogCategories(
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
                ->description('Text to match against the category name. Leave empty to see the first page.'),
            'limit' => $schema
                ->integer()
                ->description('How many to return. Defaults to 25, maximum 100.'),
        ];
    }
}
