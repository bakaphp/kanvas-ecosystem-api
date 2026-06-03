<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolInterface;
use Override;

class KanvasMessageHistory extends AbstractChatHistory
{
    private const string CONNECTION = 'intelligence';
    private const string TABLE_CONVERSATIONS = 'agent_conversations';
    private const string TABLE_MESSAGES = 'agent_conversation_messages';

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly Users $user,
        private readonly string $agentClass,
        private ?string $conversationId = null,
        int $contextWindow = 50000,
    ) {
        parent::__construct($contextWindow);

        $this->conversationId ??= $this->findLatestConversation();

        if ($this->conversationId !== null) {
            $this->load();
        }
    }

    public function getConversationId(): ?string
    {
        return $this->conversationId;
    }

    private function findLatestConversation(): ?string
    {
        return DB::connection(self::CONNECTION)
            ->table(self::TABLE_CONVERSATIONS)
            ->where('user_id', $this->user->getId())
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->orderBy('updated_at', 'desc')
            ->first()?->id;
    }

    private function load(): void
    {
        $messages = DB::connection(self::CONNECTION)
            ->table(self::TABLE_MESSAGES)
            ->where('conversation_id', $this->conversationId)
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get()
            ->map(function ($row): ?Message {
                $content = (string) ($row->content ?? '');

                if ($content === '') {
                    return null;
                }

                return $row->role === MessageRole::ASSISTANT->value
                    ? new AssistantMessage($content)
                    : new UserMessage($content);
            })
            ->filter()
            ->values()
            ->all();

        $coalesced = [];
        foreach ($messages as $m) {
            $last = end($coalesced) ?: null;
            if ($last !== null && $last->getRole() === $m->getRole()) {
                $last->setContents($last->getContent() . "\n\n" . $m->getContent());

                continue;
            }
            $coalesced[] = $m;
        }

        while (! empty($coalesced) && $coalesced[0]->getRole() !== MessageRole::USER->value) {
            array_shift($coalesced);
        }

        if (! empty($coalesced)) {
            $this->history = array_values($coalesced);
        }
    }

    #[Override]
    public function addMessage(Message $message): ChatHistoryInterface
    {
        $last = end($this->history) ?: null;
        if ($last !== null && $last->getRole() === $message->getRole()) {
            $last->setContents($last->getContent() . "\n\n" . $message->getContent());
            $this->trimHistory();
            $this->onNewMessage($message);
            $this->setMessages($this->history);

            return $this;
        }

        return parent::addMessage($message);
    }

    #[Override]
    protected function onNewMessage(Message $message): void
    {
        $role = $message->getRole();

        if (! in_array($role, [MessageRole::USER->value, MessageRole::ASSISTANT->value], true)) {
            return;
        }

        $content = (string) ($message->getContent() ?? '');
        $isToolCall = $message instanceof ToolCallMessage;
        $isToolResult = $message instanceof ToolResultMessage;

        if ($content === '' && ! $isToolCall && ! $isToolResult) {
            return;
        }

        if ($this->conversationId === null) {
            $this->conversationId = $this->createConversation($content !== '' ? $content : '[tool call]');
        } else {
            DB::connection(self::CONNECTION)
                ->table(self::TABLE_CONVERSATIONS)
                ->where('id', $this->conversationId)
                ->update(['updated_at' => now()]);
        }

        $toolCalls = $message instanceof ToolCallMessage
            ? array_map(fn (ToolInterface $tool): array => $tool->jsonSerialize(), $message->getTools())
            : [];

        $toolResults = $message instanceof ToolResultMessage
            ? array_map(fn (ToolInterface $tool): array => $tool->jsonSerialize(), $message->getTools())
            : [];

        $usage = $message->getUsage()?->jsonSerialize() ?? [];

        $meta = $message->jsonSerialize();
        unset($meta['role'], $meta['content'], $meta['usage'], $meta['tools']);

        DB::connection(self::CONNECTION)->table(self::TABLE_MESSAGES)->insert([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $this->conversationId,
            'user_id' => $this->user->getId(),
            'agent' => $this->agentClass,
            'role' => $role,
            'content' => $content,
            'attachments' => '[]',
            'tool_calls' => json_encode($toolCalls),
            'tool_results' => json_encode($toolResults),
            'usage' => json_encode($usage),
            'meta' => json_encode($meta),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createConversation(string $firstMessage): string
    {
        $conversationId = (string) Str::uuid7();

        DB::connection(self::CONNECTION)->table(self::TABLE_CONVERSATIONS)->insert([
            'id' => $conversationId,
            'user_id' => $this->user->getId(),
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company->getId(),
            'title' => Str::limit($firstMessage, 100, ''),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $conversationId;
    }
}
