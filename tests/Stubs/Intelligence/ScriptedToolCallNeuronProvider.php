<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use Override;

/**
 * Answers the first inference with a call to `$tool` and the second with a plain reply, recording what
 * the second inference was handed — i.e. the tool result exactly as the model would have received it.
 *
 * The call is conversation data (a ToolCall by name); the agent under test must hold the tool itself,
 * because ToolNode resolves every call against the agent's registry.
 */
class ScriptedToolCallNeuronProvider extends FakeNeuronProvider
{
    /** @var list<Message> */
    public array $secondCallMessages = [];

    private int $calls = 0;

    /**
     * @param array<string, mixed> $inputs
     */
    public function __construct(
        private readonly ToolInterface|string $tool,
        private readonly array $inputs = [],
    ) {
        parent::__construct();
    }

    #[Override]
    public function chat(Message ...$messages): ProviderResponse
    {
        if ($this->calls++ === 0) {
            return $this->respond(new ToolCallMessage(null, [ToolCall::make(self::toolName($this->tool), 'call-1', $this->inputs)]));
        }

        $this->secondCallMessages = $messages;

        return $this->respond(new AssistantMessage('Review done'));
    }
}
