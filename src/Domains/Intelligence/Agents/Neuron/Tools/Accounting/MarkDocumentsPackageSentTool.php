<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Scribe\SalesOrders\Actions\MarkDocumentsPackageSentAction;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use Override;

/** Records that the documents package and the due-date notice went to the customer. */
#[AgentTool(name: 'Mark Documents Package Sent', category: 'accounting')]
class MarkDocumentsPackageSentTool extends AbstractCustomerOrderTool
{
    protected string $name = 'mark_documents_package_sent';

    protected ?string $description = 'Records that the shipping documents package (commercial invoice, packing list, bill '
        . 'of lading, certificates, insurance) and the due-date notice were sent to the customer. It does not send '
        . 'anything: use it only when the person says the package went out.';

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
            'recorded',
            static function (SalesOrder $order): SalesOrder {
                new MarkDocumentsPackageSentAction($order)->execute();

                return $order->fresh();
            }
        );
    }
}
