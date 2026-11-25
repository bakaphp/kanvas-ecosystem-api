<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Scribe\SalesOrders\Actions\AcceptSalesOrderAction;
use Kanvas\Scribe\SalesOrders\Actions\ReturnSalesOrderAction;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use Override;

/** Gate 2: records Operations' answer on a confirmed sales order, either accepting it with firm dates or returning it. */
#[AgentTool(name: 'Answer Customer Order', category: 'accounting')]
class AnswerCustomerOrderTool extends AbstractCustomerOrderTool
{
    protected string $name = 'answer_customer_order';

    protected ?string $description = 'Records Operations\' answer on a confirmed sales order. accept needs firm etd '
        . '(departure) and eta (arrival) dates. return needs the reason Operations cannot meet the order, and sends it '
        . 'back to Commercial to renegotiate with the customer. Only record an answer the person reports from Operations.';

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
                name: 'decision',
                type: PropertyType::STRING,
                description: 'accept or return.',
                required: true,
            ),
            new ToolProperty(
                name: 'etd',
                type: PropertyType::STRING,
                description: 'Firm departure date as YYYY-MM-DD. Required to accept.',
                required: false,
            ),
            new ToolProperty(
                name: 'eta',
                type: PropertyType::STRING,
                description: 'Firm arrival date as YYYY-MM-DD. Required to accept.',
                required: false,
            ),
            new ToolProperty(
                name: 'reason',
                type: PropertyType::STRING,
                description: 'Why Operations cannot meet the order. Required to return.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $order_number,
        string $decision,
        ?string $etd = null,
        ?string $eta = null,
        ?string $reason = null,
    ): array {
        return $this->actOn(
            $order_number,
            'answered',
            static fn (SalesOrder $order): SalesOrder => match (strtolower(trim($decision))) {
                'accept' => new AcceptSalesOrderAction(
                    $order,
                    Carbon::parse($etd ?? throw new ValidationException('Accepting needs the firm departure date (etd).')),
                    Carbon::parse($eta ?? throw new ValidationException('Accepting needs the firm arrival date (eta).')),
                )->execute(),
                'return' => new ReturnSalesOrderAction($order, (string) $reason)->execute(),
                default => throw new ValidationException('The decision has to be accept or return.'),
            }
        );
    }
}
