<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Providers;

use NeuronAI\Providers\Mistral\Mistral;

class KanvasMistral extends Mistral
{
    use RecoversUnknownToolCalls;
}
