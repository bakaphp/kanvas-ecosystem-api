<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Providers;

use NeuronAI\Providers\Anthropic\Anthropic;

class KanvasAnthropic extends Anthropic
{
    use RecoversUnknownToolCalls;
}
