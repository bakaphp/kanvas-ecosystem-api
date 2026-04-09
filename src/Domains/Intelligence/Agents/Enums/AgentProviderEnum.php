<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Enums;

enum AgentProviderEnum: string
{
    case NEURON = 'neuron';
    case LARAVEL = 'laravel';
    case ADK = 'adk';
    case OPENCLAW = 'openclaw';
}
