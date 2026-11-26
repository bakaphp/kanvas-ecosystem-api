<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\SalesOrders\Credit\CustomerCreditService;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\TrackByInputs;

/**
 * Shared shape of the tools about a customer's credit: find the customer of the company and read their status.
 */
abstract class AbstractCustomerCreditTool extends Tool
{
    use HasKanvasContext;
    use TrackByInputs;

    protected function customerById(int $organizationId): ?Organization
    {
        return Organization::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->whereKey($organizationId)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    protected function customerNotFound(int $organizationId): array
    {
        return [
            'reason' => 'customer_not_found',
            'message' => "No customer with organization id {$organizationId} for this company.",
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function creditOf(Organization $customer): array
    {
        return new CustomerCreditService()->status($this->app, $this->company, $customer)->toArray();
    }
}
