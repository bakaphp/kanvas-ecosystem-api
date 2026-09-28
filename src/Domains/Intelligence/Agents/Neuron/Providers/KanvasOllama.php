<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Providers;

use NeuronAI\Providers\Ollama\Ollama;

class KanvasOllama extends Ollama
{
    use RecoversUnknownToolCalls;
}
