<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Providers;

use NeuronAI\Providers\OpenAILike;

class KanvasOpenAILike extends OpenAILike
{
    use RecoversUnknownToolCalls;
}
