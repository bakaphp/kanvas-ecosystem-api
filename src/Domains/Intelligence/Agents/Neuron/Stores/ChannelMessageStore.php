<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Stores;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Kanvas\Intelligence\Agents\Enums\AgentMessageTypeEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message as SocialMessage;
use Kanvas\Users\Models\Users;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use Override;

/**
 * Read-only context = the whole channel. Loads every message in a channel (human turns + prior agent
 * replies) so an agent that was @mentioned answers with the full thread in view. Each human turn is
 * prefixed with its author ("Name (@handle): …") so a multi-party thread stays legible — the agent
 * knows who said what and who it's currently talking to. The incoming mention folds into the trailing
 * turn in KanvasHistoryTrimmer. Persistence is a no-op: the reply is stored separately as a child
 * message, so this store never writes back to the channel.
 */
class ChannelMessageStore extends KanvasMessageStore
{
    public function __construct(private readonly Channel $channel)
    {
    }

    /**
     * @return list<Message>
     */
    #[Override]
    public function loadActive(string $threadId): array
    {
        $messages = $this->channel->messages()
            ->with('messageType')
            ->orderBy('messages.id', 'asc')
            ->get();

        $authorLabels = $this->authorLabels($messages);
        $turns = [];

        foreach ($messages as $message) {
            $turn = $this->toNeuronMessage($message, $authorLabels);

            if ($turn !== null) {
                $turns[] = $turn;
            }
        }

        return $turns;
    }

    #[Override]
    protected function persist(string $threadId, Message $message): void
    {
    }

    /**
     * @param array<int, string> $authorLabels
     */
    private function toNeuronMessage(SocialMessage $message, array $authorLabels): ?Message
    {
        $content = trim($message->contentText());

        // The agent's own compaction note is for humans; replaying it would summarize the summary.
        if ($content === '' || $message->messageType?->verb === AgentMessageTypeEnum::AGENT_SUMMARY->value) {
            return null;
        }

        $id = 'social:' . $message->getId();

        if ((bool) ($message->getMessage()['from_ia'] ?? false)) {
            return new AssistantMessage($content)->setId($id);
        }

        $label = $authorLabels[(int) $message->users_id] ?? 'A teammate';

        return new UserMessage($label . ': ' . $content)->setId($id);
    }

    /**
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
