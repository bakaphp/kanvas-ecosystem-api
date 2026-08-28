<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\ManagesCatalogVariants;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Edits a variant's identifiers and copy. Price and stock live on the warehouse row, so they belong
 * to set_variant_stock rather than here.
 */
#[AgentTool(name: 'Update Variant', category: 'inventory')]
class UpdateVariantTool extends Tool implements HasRunKey
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use ManagesCatalogVariants;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'update_variant',
            description: 'Edit an existing variant. Pass only the fields you want to change — anything you omit is '
                . 'left alone. Use variant_search or variant_detail to get the variant_id first. To change price, '
                . 'cost or stock use set_variant_stock instead. Only an administrator can do this.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'variant_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the variant to edit (from variant_search or variant_detail).',
                required: true,
            ),
            new ToolProperty(
                name: 'name',
                type: PropertyType::STRING,
                description: 'New variant name.',
            ),
            new ToolProperty(
                name: 'sku',
                type: PropertyType::STRING,
                description: 'New SKU. Must stay unique across this company.',
            ),
            new ToolProperty(
                name: 'description',
                type: PropertyType::STRING,
                description: 'New description for this variant.',
            ),
            new ToolProperty(
                name: 'short_description',
                type: PropertyType::STRING,
                description: 'New one-line summary of this variant.',
            ),
            new ToolProperty(
                name: 'ean',
                type: PropertyType::STRING,
                description: 'New EAN code.',
            ),
            new ToolProperty(
                name: 'barcode',
                type: PropertyType::STRING,
                description: 'New barcode.',
            ),
            new ToolProperty(
                name: 'weight',
                type: PropertyType::NUMBER,
                description: 'New shipping weight.',
            ),
            new ToolProperty(
                name: 'is_published',
                type: PropertyType::BOOLEAN,
                description: 'true to make the variant sellable, false to take it out of the storefront.',
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $variant_id,
        ?string $name = null,
        ?string $sku = null,
        ?string $description = null,
        ?string $short_description = null,
        ?string $ean = null,
        ?string $barcode = null,
        ?float $weight = null,
        ?bool $is_published = null,
    ): array {
        if ($denied = $this->requireAdminOrError()) {
            return $denied;
        }

        return $this->updateCatalogVariant(
            variantId: $variant_id,
            name: $name,
            sku: $sku,
            description: $description,
            shortDescription: $short_description,
            ean: $ean,
            barcode: $barcode,
            weight: $weight,
            isPublished: $is_published,
        );
    }
}
