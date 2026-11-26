<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Scribe\SalesOrders\Actions\SetCustomerCreditLimitAction;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/** Sets, changes or removes a customer's credit limit. */
#[AgentTool(name: 'Set Customer Credit Limit', category: 'accounting')]
class SetCustomerCreditLimitTool extends AbstractCustomerCreditTool
{
    protected string $name = 'set_customer_credit_limit';

    protected ?string $description = 'Sets a customer\'s credit limit in a stated currency, or removes it when no amount is '
        . 'given. From then on, confirming an order of that customer checks it fits in what is left of the limit, and '
        . 'releasing the shipping documents checks the customer is inside it. Use it only when the person gives the limit '
        . 'or asks to remove it: a limit is a finance decision, never infer one.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'customer_organization_id',
                type: PropertyType::NUMBER,
                description: 'The Kanvas organization id of the customer (find_customer returns it).',
                required: true,
            ),
            new ToolProperty(
                name: 'amount',
                type: PropertyType::NUMBER,
                description: 'The credit limit. Leave it out to remove the limit.',
                required: false,
            ),
            new ToolProperty(
                name: 'currency',
                type: PropertyType::STRING,
                description: 'Three-letter currency the limit is stated in. Required with an amount.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $customer_organization_id, ?float $amount = null, ?string $currency = null): array
    {
        $customer = $this->customerById($customer_organization_id);

        if ($customer === null) {
            return ['set' => false] + $this->customerNotFound($customer_organization_id);
        }

        try {
            new SetCustomerCreditLimitAction($customer, $amount, $currency)->execute();
        } catch (Throwable $e) {
            return ['set' => false, 'reason' => 'not_set', 'message' => $e->getMessage()];
        }

        return ['set' => true] + $this->creditOf($customer);
    }
}
