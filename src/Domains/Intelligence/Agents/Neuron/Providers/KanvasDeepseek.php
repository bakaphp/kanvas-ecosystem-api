<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Providers;

use NeuronAI\Providers\Deepseek\Deepseek;

class KanvasDeepseek extends Deepseek
{
    use RecoversUnknownToolCalls;
}
