<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use Kanvas\Intelligence\Agents\Neuron\KanvasGenericNeuronAgent;
use NeuronAI\Chat\History\MessageStoreInterface;
use Override;
use Tests\Stubs\Intelligence\Concerns\UsesLeadSummaryStubProviders;

/**
 * The internal twin of LeadSummaryCustomerAgentStub: the lead's whole rollup, internal rows included,
 * and no customer marker, so a gap is answered with the dates and the lead tool instead of an excerpt.
 */
class LeadSummaryInternalAgentStub extends KanvasGenericNeuronAgent
{
    use UsesLeadSummaryStubProviders;

    #[Override]
    protected function messageStore(): MessageStoreInterface
    {
        return $this->entityRollupStore(sessionThreadId: null, includeInternal: true);
    }

    #[Override]
    public function instructions(): string
    {
        return 'Internal test agent';
    }
}
