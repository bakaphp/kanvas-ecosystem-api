<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Fallback;

use NeuronAI\Tools\Tool;

/**
 * Stands in for a live tool the turn is no longer allowed to run. ToolNode resolves every call against
 * the segment's registry, so swapping the real tool for this one is how a call is refused without
 * touching the call's approval state; the model reads the feedback as the tool's result.
 */
final class RefusedToolStub extends Tool
{
    public function __construct(string $name, private readonly string $feedback)
    {
        $this->name = $name;
        $this->description = 'Refused for the rest of this turn.';
    }

    public function __invoke(mixed ...$arguments): string
    {
        return $this->feedback;
    }
}
