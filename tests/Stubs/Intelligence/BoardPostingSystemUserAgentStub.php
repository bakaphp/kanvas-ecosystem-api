<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\PostChannelMessageAction;
use NeuronAI\Agent\AgentHandler;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use Override;

/**
 * An agent that answers on the board DURING its turn, the way one holding
 * comment_on_nervous_system_plan does, and then returns a turn that says the same thing.
 *
 * The duplicate only exists in that ordering — a comment posted before the run is just history — so a
 * stub that writes mid-turn is the only way to exercise the guard through the real job.
 */
class BoardPostingSystemUserAgentStub extends SystemUserAgentStub
{
    public static ?int $postToChannelId = null;

    #[Override]
    protected function provider(): AIProviderInterface
    {
        return new FakeNeuronProvider('Hola Sistema');
    }

    #[Override]
    public function chat(Message|array $messages = [], ?InterruptRequest $interrupt = null): AgentHandler
    {
        $channel = self::$postToChannelId !== null
            ? Channel::query()->where('id', self::$postToChannelId)->first()
            : null;

        if ($channel instanceof Channel && $this->agent?->user !== null) {
            new PostChannelMessageAction(
                channel: $channel,
                author: $this->agent->user,
                verb: 'agent_reply',
                content: 'Answered on the board mid-turn.',
                extraPayload: ['from_me' => true, 'from_agent' => true],
            )->execute();
        }

        return parent::chat($messages, $interrupt);
    }
}
