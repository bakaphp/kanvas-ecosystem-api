<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Observability;

use NeuronAI\Observability\ObservabilityEvent;
use Override;

/**
 * Neuron emits nothing while an agent builds its resources, so a slow history load or lead summary is
 * invisible to a listener. The agent marks each finished step with one of these.
 */
final class TurnSetupStage extends ObservabilityEvent
{
    public function __construct(public readonly string $stage)
    {
    }

    #[Override]
    public function name(): string
    {
        return 'setup-' . $this->stage;
    }

    #[Override]
    public function toArray(): array
    {
        return ['stage' => $this->stage];
    }
}
