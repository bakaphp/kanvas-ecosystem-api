<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Providers;

use NeuronAI\Providers\OpenAI\OpenAI;

class KanvasOpenAI extends OpenAI
{
    use RecoversUnknownToolCalls;
}
