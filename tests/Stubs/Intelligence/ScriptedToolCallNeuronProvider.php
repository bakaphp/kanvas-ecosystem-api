<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Tools\Tool;
use Override;

/**
 * Answers the first inference with a call to `$tool` and the second with a plain reply, recording what
 * the second inference was handed — i.e. the tool result exactly as the model would have received it.
 */
class ScriptedToolCallNeuronProvider extends CapturingNeuronProvider
{
    /** @var list<Message> */
    public array $secondCallMessages = [];

    private int $calls = 0;

    public function __construct(
        private readonly Tool $tool,
    ) {
        parent::__construct();
    }

    #[Override]
    public function chat(Message ...$messages): Message
    {
        if ($this->calls++ === 0) {
            return new ToolCallMessage(null, [$this->tool->setInputs([])->setCallId('call-1')]);
        }

        $this->secondCallMessages = $messages;

        return new AssistantMessage('Review done');
    }
}
