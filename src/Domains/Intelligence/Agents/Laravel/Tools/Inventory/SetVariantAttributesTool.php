<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Inventory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Laravel\Traits\HandlesToolRequest;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\ManagesCatalogVariants;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

/**
 * Laravel-AI counterpart of the Neuron set_variant_attributes tool.
 */
#[AgentTool(name: 'Set Variant Attributes', category: 'inventory')]
class SetVariantAttributesTool implements KanvasToolInterface
{
    use GuardsAdminForTool;
    use HandlesToolRequest;
    use HasKanvasContext;
    use ManagesCatalogVariants;

    public function name(): string
    {
        return 'set_variant_attributes';
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Set spec attributes on one variant — the facts that differ per SKU, e.g. Size or Colour. Pass a '
            . 'JSON object of name to value. An attribute that does not exist yet is created. Attributes you do '
            . 'not mention are left alone. Check attribute_search first so you reuse the company\'s existing '
            . 'attribute names. For a fact shared by every SKU of the product use set_product_attributes instead. '
            . 'Only an administrator can do this.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $denied = $this->adminDenialFor('change variant attributes');

        if ($denied !== null) {
            return $denied;
        }

        return (string) json_encode(
            $this->setCatalogVariantAttributes(
                variantId: $request->integer('variant_id'),
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
            'variant_id' => $schema
                ->integer()
                ->description('The ID of the variant (from variant_search or variant_detail).')
                ->required(),
            'attributes' => $schema
                ->string()
                ->description(
                    'A JSON object mapping attribute name to value, passed as a string. For example: '
                    . '{"Size": "XL", "Colour": "Black"}'
                ),
            'remove' => $schema
                ->array()
                ->items($schema->string()->description('An attribute name, exactly as attribute_search spells it.'))
                ->description(
                    'Attribute names to remove from the variant entirely. Setting a value to an empty string does NOT '
                    . 'remove it — name it here instead.'
                ),
        ];
    }
}
