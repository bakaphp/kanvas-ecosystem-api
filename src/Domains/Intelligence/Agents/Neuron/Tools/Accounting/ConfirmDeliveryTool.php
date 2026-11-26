<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Scribe\SalesOrders\Actions\ConfirmDeliveryAction;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use Override;

/** Records the delivery of a shipped customer order against its signed proof of delivery. */
#[AgentTool(name: 'Confirm Delivery', category: 'accounting')]
class ConfirmDeliveryTool extends AbstractCustomerOrderTool
{
    protected string $name = 'confirm_delivery';

    protected ?string $description = 'Records the delivery of a shipped customer order against the signed proof of delivery '
        . '(POD), with any discrepancy or damage written on it. Discrepancies are reported within 48 hours of delivery. '
        . 'Use it only when the person says the goods were delivered and gives the POD reference.';

    /**
     * @return array<int, ToolProperty>
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
                name: 'delivered_on',
                type: PropertyType::STRING,
                description: 'The delivery date as YYYY-MM-DD.',
                required: true,
            ),
            new ToolProperty(
                name: 'pod_reference',
                type: PropertyType::STRING,
                description: 'The reference of the signed proof of delivery.',
                required: true,
            ),
            new ToolProperty(
                name: 'discrepancies',
                type: PropertyType::STRING,
                description: 'What the customer wrote on the proof of delivery about missing or different goods.',
                required: false,
            ),
            new ToolProperty(
                name: 'damage_reported',
                type: PropertyType::BOOLEAN,
                description: 'True when damage was reported, which starts a cargo claim to the carrier and the insurer.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $order_number,
        string $delivered_on,
        string $pod_reference,
        ?string $discrepancies = null,
        ?bool $damage_reported = null,
    ): array {
        return $this->actOn(
            $order_number,
            'delivered',
            static function (SalesOrder $order) use ($delivered_on, $pod_reference, $discrepancies, $damage_reported): SalesOrder {
                new ConfirmDeliveryAction(
                    order: $order,
                    deliveredOn: Carbon::parse($delivered_on),
                    podReference: $pod_reference,
                    discrepancies: $discrepancies,
                    damageReported: $damage_reported ?? false,
                )->execute();

                return $order->fresh();
            }
        );
    }
}
