<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use NeuronAI\Providers\AIProviderInterface;
use Override;

class SystemUserAgentStub extends SystemUserAgent
{
    #[Override]
    protected function provider(): AIProviderInterface
    {
        return new FakeNeuronProvider('Hola Sistema');
    }
}
