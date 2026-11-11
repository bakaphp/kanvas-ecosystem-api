<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use Kanvas\Intelligence\Agents\Contracts\ConversesWithCustomer;
use Kanvas\Intelligence\Agents\Neuron\Concerns\HasProspectIsolatedHistory;
use Kanvas\Intelligence\Agents\Neuron\KanvasGenericNeuronAgent;
use Override;
use Tests\Stubs\Intelligence\Concerns\UsesLeadSummaryStubProviders;

/**
 * A customer-facing agent on a lead's rollup with the rolling summary on.
 */
class LeadSummaryCustomerAgentStub extends KanvasGenericNeuronAgent implements ConversesWithCustomer
{
    use HasProspectIsolatedHistory;
    use UsesLeadSummaryStubProviders;

    #[Override]
    protected function remembersForCompany(): bool
    {
        return false;
    }

    #[Override]
    public function instructions(): string
    {
        return 'Customer-facing test agent';
    }
}
