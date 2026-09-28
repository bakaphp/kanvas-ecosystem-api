<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Tools\Tool;
use Override;

/**
 * Calls `$tool` `$rounds` times in one turn, each with its own inputs, then replies — enough to walk a
 * real agent loop past its tool-output budget. A fresh clone per round, as NeuronAI hands each call
 * its own copy, so refusing one call cannot leak into the next.
 */
class RepeatingToolCallNeuronProvider extends CapturingNeuronProvider
{
    private int $calls = 0;

    public function __construct(
        private readonly Tool $tool,
        private readonly int $rounds,
    ) {
        parent::__construct();
    }

    #[Override]
    public function chat(Message ...$messages): Message
    {
        $round = ++$this->calls;

        if ($round > $this->rounds) {
            return new AssistantMessage('Batch reported');
        }

        $call = clone $this->tool;

        return new ToolCallMessage(null, [$call->setInputs(['item' => $round])->setCallId('call-' . $round)]);
    }
}
