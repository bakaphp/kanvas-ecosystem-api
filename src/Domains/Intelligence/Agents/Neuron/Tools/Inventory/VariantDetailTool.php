<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Inventory\Variants\Models\Variants;
use NeuronAI\Tools\PropertyType as ToolsPropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

#[AgentTool(name: 'Variant Detail', category: 'inventory')]
class VariantDetailTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'variant_detail',
            description: 'Get the full detail of a single product variant: identifiers (SKU, EAN, barcode), '
                . 'descriptions, weight, publish state, parent product, inventory per warehouse '
                . '(stock, price, cost, flags) including the channels priced on each warehouse, attributes and media. '
                . 'Use this after inventory_search/variant_search when the user wants the deep details of one specific variant.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'variant_id',
                type: ToolsPropertyType::INTEGER,
                description: 'The numeric ID of the product variant to fetch.',
                required: true,
            ),
        ];
    }

    public function __invoke(int $variant_id): array
    {
        try {
            $variant = Variants::getById($variant_id, app(Apps::class));
        } catch (Throwable $e) {
            return ['message' => "Variant #{$variant_id} not found."];
        }

        $variant->load([
            'product:id,name,slug,is_published',
            'status:id,name',
            'variantWarehouses.warehouse:id,name',
            'variantWarehouses.status:id,name',
            'variantWarehouses.variantChannels.channel:id,name,is_default,is_published',
        ]);

        return [
            'id' => $variant->getId(),
            'name' => $variant->name,
            'sku' => $variant->sku,
            'ean' => $variant->ean,
            'barcode' => $variant->barcode,
            'weight' => (float) $variant->weight,
            'description' => $variant->description,
            'short_description' => $variant->short_description,
            'serial_number' => $variant->serial_number,
            'is_published' => (bool) $variant->is_published,
            'status' => $variant->status === null ? null : [
                'id' => $variant->status->getId(),
                'name' => $variant->status->name,
            ],
            'product' => $variant->product === null ? null : [
                'id' => $variant->product->getId(),
                'name' => $variant->product->name,
                'slug' => $variant->product->slug,
                'is_published' => (bool) $variant->product->is_published,
            ],
            'inventory' => $variant->variantWarehouses->map(fn ($vw) => [
                'warehouse_id' => (int) $vw->warehouses_id,
                'warehouse_name' => $vw->warehouse?->name,
                'sku' => $vw->sku,
                'stock' => (int) $vw->quantity,
                'price' => (float) $vw->price,
                'cost' => (float) $vw->cost,
                'is_new' => (bool) $vw->is_new,
                'is_oversellable' => (bool) $vw->is_oversellable,
                'is_on_sale' => (bool) $vw->is_on_sale,
                'is_on_promo' => (bool) $vw->is_on_promo,
                'can_pre_order' => (bool) $vw->can_pre_order,
                'channels' => $vw->variantChannels->map(fn ($vc) => [
                    'channel_id' => (int) $vc->channels_id,
                    'channel_name' => $vc->channel?->name,
                    'default_price' => (float) $vc->price,
                    'selling_price' => (float) $vc->discounted_price,
                    'is_active' => (bool) $vc->is_published,
                ])->values()->toArray(),
            ])->values()->toArray(),
            'attributes' => collect($variant->searchableAttributes())->map(fn ($attr) => [
                'name' => $attr['name'],
                'value' => $attr['value'],
            ])->values()->toArray(),
            'media' => $variant->getFiles()->map(fn ($file) => [
                'uuid' => $file->uuid,
                'name' => $file->name,
                'url' => $file->url,
                'field_name' => $file->field_name,
            ])->values()->toArray(),
        ];
    }
}
