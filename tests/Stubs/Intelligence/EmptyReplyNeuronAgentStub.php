<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use NeuronAI\Providers\AIProviderInterface;
use Override;

class EmptyReplyNeuronAgentStub extends SalesNeuronAgentStub
{
    #[Override]
    protected function provider(): AIProviderInterface
    {
        return new FakeNeuronProvider('');
    }
}
