<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Scribe\SalesOrders\Actions\RecordShipmentAction;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use Override;

/** Records the bill of lading of an accepted customer order and sets its invoice due from that date. */
#[AgentTool(name: 'Record Shipment', category: 'accounting')]
class RecordShipmentTool extends AbstractCustomerOrderTool
{
    protected string $name = 'record_shipment';

    protected ?string $description = 'Records the bill of lading of an accepted customer order once its shipping documents '
        . 'were cross-checked, every difference clarified and its invoice issued: goods ship against a final invoice, '
        . 'so a draft is refused and has to be issued first. The order ships, and the invoice falls due the order\'s '
        . 'payment term after the bill of lading date (not after the invoice date). Returns the due date, when the '
        . 'documents package is due and the collection plan. An invoice edited after the check has to go through '
        . 'check_shipping_documents again first. Use it only when the person says the bill of lading was issued.';

    /**
     * @return array<int, ToolProperty|ArrayProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'order_number',
                type: PropertyType::STRING,
                description: 'The customer order number, e.g. SO-000012.',
                required: true,
            ),
            new ToolProperty(
                name: 'bl_number',
                type: PropertyType::STRING,
                description: 'The bill of lading number.',
                required: true,
            ),
            new ToolProperty(
                name: 'bl_date',
                type: PropertyType::STRING,
                description: 'The bill of lading date as YYYY-MM-DD: day 0 of the payment term.',
                required: true,
            ),
            new ToolProperty(
                name: 'vessel',
                type: PropertyType::STRING,
                description: 'The vessel name.',
                required: false,
            ),
            new ArrayProperty(
                name: 'containers',
                description: 'The container numbers.',
                required: false,
                items: new ToolProperty(
                    name: 'container',
                    type: PropertyType::STRING,
                    description: 'A container number.',
                    required: true,
                ),
            ),
            new ToolProperty(
                name: 'eta',
                type: PropertyType::STRING,
                description: 'The confirmed arrival date as YYYY-MM-DD, when it changed since the order was accepted.',
                required: false,
            ),
        ];
    }

    /**
     * @param list<string>|null $containers
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        string $order_number,
        string $bl_number,
        string $bl_date,
        ?string $vessel = null,
        ?array $containers = null,
        ?string $eta = null,
    ): array {
        $plan = [];

        $result = $this->actOn(
            $order_number,
            'shipped',
            function (SalesOrder $order) use (&$plan, $bl_number, $bl_date, $vessel, $containers, $eta): SalesOrder {
                new RecordShipmentAction(
                    order: $order,
                    blNumber: $bl_number,
                    blDate: Carbon::parse($bl_date),
                    user: $this->user,
                    vessel: $vessel,
                    containers: $containers ?? [],
                    eta: $eta === null ? null : Carbon::parse($eta),
                )->execute();

                $shipped = $order->fresh();
                $plan = $shipped->collectionPlan();

                return $shipped;
            }
        );

        return $plan === [] ? $result : $result + ['collection_plan' => $plan];
    }
}
