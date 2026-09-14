<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Neuron\Tools;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Inventory\Stats\Repositories\ProductStatsRepository;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Movipass Parking Capacity', category: 'commerce')]
class ParkingCapacityTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'movipass_parking_capacity',
            description: 'Live occupancy of the parking lots: total spaces, how many are free right now, how many '
                . 'are taken, and the occupancy percentage. Use for "is the lot full", "how many spaces are left", '
                . '"what is our occupancy today", capacity planning and overflow decisions. This is a live snapshot '
                . 'read off the warehouse stock, not a historical series — for entries and exits over time use '
                . 'movipass_order_turnover. Omit every argument to get the whole company; narrow with '
                . 'product_type_slug (e.g. "parking") or a specific lot.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'product_type_slug', type: PropertyType::STRING, description: 'Restrict to one product type slug, e.g. "parking". Omit for every type.', required: false),
            new ToolProperty(name: 'product_ids', type: PropertyType::STRING, description: 'Comma-separated product ids to restrict to (one per lot). Omit for every lot.', required: false),
            new ToolProperty(name: 'warehouse_id', type: PropertyType::INTEGER, description: 'Restrict to a single warehouse (a level or zone of a lot). Omit for all.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $product_type_slug = null,
        ?string $product_ids = null,
        ?int $warehouse_id = null,
    ): array {
        $ids = array_values(array_filter(
            array_map('intval', explode(',', (string) $product_ids)),
            fn (int $id): bool => $id > 0,
        ));

        $stats = ProductStatsRepository::getCapacityStats(
            $this->app,
            $this->company,
            $product_type_slug,
            $ids === [] ? null : $ids,
            $warehouse_id,
            $this->company->getId(),
        );

        return [
            'max_capacity' => $stats->maxCapacity,
            'available_capacity' => $stats->availableCapacity,
            'occupied_capacity' => $stats->occupiedCapacity,
            'occupancy_percentage' => $stats->occupancyPercentage,
        ];
    }
}
