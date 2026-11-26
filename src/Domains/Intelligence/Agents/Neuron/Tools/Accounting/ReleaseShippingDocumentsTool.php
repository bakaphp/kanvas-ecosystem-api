<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Scribe\SalesOrders\Actions\ReleaseDocumentsAction;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use Override;

/** Gate 3: releases the original bill of lading or the telex release of a shipped customer order. */
#[AgentTool(name: 'Release Shipping Documents', category: 'accounting')]
class ReleaseShippingDocumentsTool extends AbstractCustomerOrderTool
{
    protected string $name = 'release_shipping_documents';

    protected ?string $description = 'Records that the original bill of lading, or the telex release, of a shipped customer '
        . 'order is released to the customer. It is refused when the customer has an overdue invoice, is over their credit '
        . 'limit, or their new orders are on hold. It releases nothing itself: use it only when the person says Operations '
        . 'is releasing the documents.';

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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $order_number): array
    {
        return $this->actOn(
            $order_number,
            'released',
            static function (SalesOrder $order): SalesOrder {
                new ReleaseDocumentsAction($order)->execute();

                return $order->fresh();
            }
        );
    }
}
