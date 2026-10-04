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
 * Calls `$tool` `$rounds` times in one turn, each with its own inputs, then replies — enough to walk a
 * real agent loop past its tool-output budget. Each round is its own ToolCall, so refusing one call
 * cannot leak into the next.
 */
class RepeatingToolCallNeuronProvider extends FakeNeuronProvider
{
    private int $calls = 0;

    public function __construct(
        private readonly ToolInterface|string $tool,
        private readonly int $rounds,
    ) {
        parent::__construct();
    }

    #[Override]
    public function chat(Message ...$messages): ProviderResponse
    {
        $round = ++$this->calls;

        if ($round > $this->rounds) {
            return $this->respond(new AssistantMessage('Batch reported'));
        }

        return $this->respond(new ToolCallMessage(null, [ToolCall::make(self::toolName($this->tool), 'call-' . $round, ['item' => $round])]));
    }
}
