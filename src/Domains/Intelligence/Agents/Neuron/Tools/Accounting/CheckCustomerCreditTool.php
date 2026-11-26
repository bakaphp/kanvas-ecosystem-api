<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use Override;

/** Reads where a customer stands against their credit limit. */
#[AgentTool(name: 'Check Customer Credit', category: 'accounting')]
class CheckCustomerCreditTool extends AbstractCustomerCreditTool
{
    protected string $name = 'check_customer_credit';

    protected ?string $description = 'Reads where a customer stands against their credit limit: the limit, what they owe on '
        . 'open invoices, what is committed on confirmed orders that have no invoice yet, the overdue invoices, what is '
        . 'left to use, and whether their new orders are on hold because an invoice is overdue beyond the hold days. '
        . 'Amounts are in the limit\'s currency; if a rate is missing it says which pair instead of guessing. Read-only.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'customer_organization_id',
                type: PropertyType::INTEGER,
                description: 'The Kanvas organization id of the customer (find_customer returns it).',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $customer_organization_id): array
    {
        $customer = $this->customerById($customer_organization_id);

        if ($customer === null) {
            return $this->customerNotFound($customer_organization_id);
        }

        return [
            'found' => true,
            'customer_organization_id' => $customer_organization_id,
            ...$this->creditOf($customer),
        ];
    }
}
