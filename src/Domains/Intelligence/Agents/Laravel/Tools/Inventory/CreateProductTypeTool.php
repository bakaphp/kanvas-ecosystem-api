<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Inventory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Laravel\Traits\HandlesToolRequest;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\ManagesCatalogTaxonomy;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

/**
 * Laravel-AI counterpart of the Neuron create_product_type tool.
 */
#[AgentTool(name: 'Create Product Type', category: 'inventory')]
class CreateProductTypeTool implements KanvasToolInterface
{
    use GuardsAdminForTool;
    use HandlesToolRequest;
    use HasKanvasContext;
    use ManagesCatalogTaxonomy;

    public function name(): string
    {
        return 'create_product_type';
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Create a product type — the grouping for products that share a set of attributes, e.g. "Footwear" '
            . 'or "Laptop". Check list_product_types first; a type with the same name is reused rather than '
            . 'duplicated. Pass the new id as product_type_id on create_product or update_product. Only an '
            . 'administrator can do this.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $denied = $this->adminDenialFor('create product types');

        if ($denied !== null) {
            return $denied;
        }

        return (string) json_encode(
            $this->createCatalogProductType(
                name: (string) $request->string('name'),
                description: $this->nullableString($request, 'description'),
                isPublished: $this->nullableBool($request, 'is_published'),
            ),
            JSON_PRETTY_PRINT
        );
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema
                ->string()
                ->description('The product type name.')
                ->required(),
            'description' => $schema
                ->string()
                ->description('What kind of products belong to this type.'),
            'is_published' => $schema
                ->boolean()
                ->description('Whether the type is active. Defaults to true.'),
        ];
    }
}
