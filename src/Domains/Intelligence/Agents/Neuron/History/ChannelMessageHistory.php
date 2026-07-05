<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\History;

use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message as SocialMessage;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use Override;

/**
 * Read-only context = the whole channel. Loads every message in a channel (human turns
 * + prior agent replies) so an agent that was @mentioned answers with the full thread in
 * view. Persistence is a no-op: the reply is stored separately as a child message, so this
 * history never writes back to the channel.
 */
class ChannelMessageHistory extends AbstractChatHistory
{
    public function __construct(
        private readonly Channel $channel,
        int $contextWindow = 50000,
    ) {
        parent::__construct($contextWindow);
        $this->load();
    }

    /**
     * Merge consecutive same-role turns so the incoming mention (added by the runner) folds
     * into the channel's trailing turn instead of breaking user/assistant alternation, which
     * providers reject. This is the one place coalescing happens — load() feeds through here too.
     */
    #[Override]
    public function addMessage(Message $message): ChatHistoryInterface
    {
        $last = $this->history === [] ? null : $this->history[array_key_last($this->history)];

        if ($last !== null && $last->getRole() === $message->getRole()) {
            $last->setContents((string) $last->getContent() . "\n\n" . (string) $message->getContent());
            $this->trimHistory();

            return $this;
        }

        return parent::addMessage($message);
    }

    private function load(): void
    {
        $this->channel->messages()
            ->orderBy('messages.id', 'asc')
            ->get()
            ->each(function (SocialMessage $message): void {
                $turn = $this->toNeuronMessage($message);

                if ($turn !== null) {
                    $this->addMessage($turn);
                }
            });

        // Providers require the history to start with a user turn.
        while ($this->history !== [] && $this->history[0]->getRole() !== MessageRole::USER->value) {
            array_shift($this->history);
        }
    }

    private function toNeuronMessage(SocialMessage $message): ?Message
    {
        $content = trim($message->contentText());

        if ($content === '') {
            return null;
        }

        return (bool) ($message->getMessage()['from_ia'] ?? false)
            ? new AssistantMessage($content)
            : new UserMessage($content);
    }
}
