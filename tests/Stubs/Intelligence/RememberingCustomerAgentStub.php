<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use Kanvas\Intelligence\Agents\Contracts\ConversesWithCustomer;
use Kanvas\Intelligence\Agents\Neuron\BaseRagAgent;
use Kanvas\Intelligence\Agents\Neuron\Concerns\HasProspectIsolatedHistory;
use Override;
use Tests\Stubs\Intelligence\Concerns\RemembersInProcess;

/**
 * A customer-facing agent on the shared in-memory company store, so a test can prove it recalls its own
 * prospect and never another one.
 */
class RememberingCustomerAgentStub extends BaseRagAgent implements ConversesWithCustomer
{
    use HasProspectIsolatedHistory;
    use RemembersInProcess {
        RemembersInProcess::messageStore insteadof HasProspectIsolatedHistory;
    }

    protected function providerReply(): string
    {
        return 'Noted, thank you.';
    }

    #[Override]
    public function instructions(): string
    {
        return 'Customer-facing test agent';
    }
}
