<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Providers;

use NeuronAI\Providers\Gemini\Gemini;

class KanvasGemini extends Gemini
{
    use RecoversUnknownToolCalls;
}
