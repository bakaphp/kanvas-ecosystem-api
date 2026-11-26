<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Scribe\SalesOrders\Actions\ReviewSalesOrderAction;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use Override;

/** Reviews a received sales order against its quote and the agreed terms, and lists every difference. */
#[AgentTool(name: 'Review Customer PO', category: 'accounting')]
class ReviewCustomerPoTool extends AbstractCustomerOrderTool
{
    protected string $name = 'review_customer_po';

    protected ?string $description = 'Reviews a received sales order against its quote (and the agreed terms, when given) '
        . 'and records every difference: currency, total, and each line\'s quantity and unit price, plus the Incoterm and '
        . 'payment term when the agreed values are passed. Every difference has to be clarified with the customer in '
        . 'writing before the order can be confirmed. Run it again after any correction.';

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
                description: 'The sales order number, e.g. SO-000012.',
                required: true,
            ),
            new ToolProperty(
                name: 'agreed_incoterm',
                type: PropertyType::STRING,
                description: 'The Incoterm that was agreed with the customer, to compare with the order\'s.',
                required: false,
            ),
            new ToolProperty(
                name: 'agreed_payment_terms_days',
                type: PropertyType::INTEGER,
                description: 'The payment term in days that was agreed with the customer, to compare with the order\'s.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $order_number,
        ?string $agreed_incoterm = null,
        ?int $agreed_payment_terms_days = null,
    ): array {
        $agreement = array_filter([
            'incoterm' => $agreed_incoterm,
            'payment_terms_days' => $agreed_payment_terms_days,
        ], static fn (mixed $value): bool => $value !== null);

        return $this->actOn(
            $order_number,
            'reviewed',
            static fn (SalesOrder $order): SalesOrder => new ReviewSalesOrderAction($order, $agreement)
                ->execute()
        );
    }
}
