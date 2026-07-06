<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\History;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message as SocialMessage;
use Kanvas\Users\Models\Users;
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
 * view. Each human turn is prefixed with its author ("Name (@handle): …") so a multi-party
 * thread stays legible — the agent knows who said what and who it's currently talking to.
 * Persistence is a no-op: the reply is stored separately as a child message, so this history
 * never writes back to the channel.
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
        $messages = $this->channel->messages()
            ->orderBy('messages.id', 'asc')
            ->get();

        $authorLabels = $this->authorLabels($messages);

        foreach ($messages as $message) {
            $turn = $this->toNeuronMessage($message, $authorLabels);

            if ($turn !== null) {
                $this->addMessage($turn);
            }
        }

        // Providers require the history to start with a user turn.
        while ($this->history !== [] && $this->history[0]->getRole() !== MessageRole::USER->value) {
            array_shift($this->history);
        }
    }

    /**
     * @param array<int, string> $authorLabels
     */
    private function toNeuronMessage(SocialMessage $message, array $authorLabels): ?Message
    {
        $content = trim($message->contentText());

        if ($content === '') {
            return null;
        }

        if ((bool) ($message->getMessage()['from_ia'] ?? false)) {
            return new AssistantMessage($content);
        }

        $label = $authorLabels[(int) $message->users_id] ?? 'A teammate';

        return new UserMessage($label . ': ' . $content);
    }

    /**
     * Resolve every distinct author in one query so each human turn can be attributed without
     * N per-message lookups.
     *
     * @param EloquentCollection<int, SocialMessage> $messages
     *
     * @return array<int, string>
     */
    private function authorLabels(EloquentCollection $messages): array
    {
        $userIds = $messages->pluck('users_id')->filter()->unique()->values()->all();

        if ($userIds === []) {
            return [];
        }

        $labels = [];
        foreach (Users::query()->whereIn('id', $userIds)->get(['id', 'firstname', 'lastname', 'displayname']) as $user) {
            $name = trim($user->firstname . ' ' . $user->lastname);
            $handle = (string) $user->displayname;

            $labels[(int) $user->id] = match (true) {
                $name !== '' && $handle !== '' => $name . ' (@' . $handle . ')',
                $name !== '' => $name,
                $handle !== '' => '@' . $handle,
                default => 'User #' . $user->id,
            };
        }

        return $labels;
    }
}
