<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Providers;

use NeuronAI\Providers\XAI\Grok;

class KanvasGrok extends Grok
{
    use RecoversUnknownToolCalls;
}
