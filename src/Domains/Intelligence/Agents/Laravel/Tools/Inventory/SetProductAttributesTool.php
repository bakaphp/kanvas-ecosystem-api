<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Inventory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Laravel\Traits\HandlesToolRequest;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\ManagesCatalogProducts;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

/**
 * Laravel-AI counterpart of the Neuron set_product_attributes tool.
 */
#[AgentTool(name: 'Set Product Attributes', category: 'inventory')]
class SetProductAttributesTool implements KanvasToolInterface
{
    use GuardsAdminForTool;
    use HandlesToolRequest;
    use HasKanvasContext;
    use ManagesCatalogProducts;

    public function name(): string
    {
        return 'set_product_attributes';
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Set spec attributes on a product — the facts shared by every variant, e.g. Material, Brand or '
            . 'Warranty. Pass a JSON object of name to value. An attribute that does not exist yet is created. '
            . 'Attributes you do not mention are left alone. Check attribute_search first so you reuse the '
            . 'company\'s existing attribute names instead of creating near-duplicates. For a fact that differs '
            . 'per SKU (size, colour) use set_variant_attributes instead. Only an administrator can do this.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $denied = $this->adminDenialFor('change product attributes');

        if ($denied !== null) {
            return $denied;
        }

        return (string) json_encode(
            $this->setCatalogProductAttributes(
                productId: $request->integer('product_id'),
                attributes: $this->jsonObjectParam($request, 'attributes'),
                remove: $request->array('remove'),
            ),
            JSON_PRETTY_PRINT
        );
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema
                ->integer()
                ->description('The ID of the product (from list_available_products or inventory_search).')
                ->required(),
            'attributes' => $schema
                ->string()
                ->description(
                    'A JSON object mapping attribute name to value, passed as a string. For example: '
                    . '{"Material": "Cotton", "Brand": "Acme", "Warranty": "2 years"}'
                ),
            'remove' => $schema
                ->array()
                ->items($schema->string()->description('An attribute name, exactly as attribute_search spells it.'))
                ->description(
                    'Attribute names to remove from the product entirely. Setting a value to an empty string does NOT '
                    . 'remove it — name it here instead.'
                ),
        ];
    }
}
