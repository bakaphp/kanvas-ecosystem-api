<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\AppModuleMessage;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\Users\Models\Users;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use Override;

class SalesAssistKanvasMessageHistory extends AbstractChatHistory
{
    private const string AGENT_VERB = 'agent';
    private const string USER_VERB = 'user';

    // Verbos que nunca deben exponerse al lead
    private const array INTERNAL_VERBS = ['notes', 'ai_assist', 'internal'];

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly Users $user,
        private readonly Model $entity,
        private readonly ?string $threadId = null,
        private readonly bool $includeInternal = false,
        int $contextWindow = 50000,
    ) {
        parent::__construct($contextWindow);
        $this->load();
    }

    private function load(): void
    {
        $messages = AppModuleMessage::query()
            ->where('system_modules', get_class($this->entity))
            ->where('entity_id', $this->entity->getKey())
            ->where('apps_id', $this->app->getId())
            ->whereHas('message', fn ($q) => $q->where('is_deleted', 0))
            ->with(['message.messageType'])
            ->orderBy('id', 'asc')
            ->get()
            ->map(function (AppModuleMessage $appModuleMessage): ?Message {
                $socialMessage = $appModuleMessage->message;

                if (! $socialMessage) {
                    return null;
                }

                $stored = $socialMessage->getMessage();

                if ($this->threadId !== null && ($stored['thread_id'] ?? null) !== $this->threadId) {
                    return null;
                }

                $verb = $socialMessage->messageType?->verb ?? self::USER_VERB;

                if (! $this->includeInternal && in_array($verb, self::INTERNAL_VERBS, true)) {
                    return null;
                }

                $text = (string) ($stored['content'] ?? $stored['text'] ?? '');

                if ($text === '') {
                    return null;
                }

                $fromIa = (bool) ($stored['from_ia'] ?? false);
                $fromHuman = (bool) ($stored['from_human'] ?? false);

                $channel = $socialMessage->channels()->first();
                $isInternal = $channel?->isNoteChannel() || $channel?->isAiAssistChannel();

                if ($isInternal) {
                    $prefixed = "[INTERNAL - {$channel->name}] {$text}";
                } elseif ($fromIa) {
                    $prefixed = "[Assistant] {$text}";
                } elseif ($fromHuman) {
                    $owner = $socialMessage->user?->displayname ?: 'Owner';
                    $prefixed = "[Owner - {$owner}] {$text}";
                } else {
                    $prefixed = '[' . $this->entityIdentityLabel() . "] {$text}";
                }

                return $fromIa
                    ? new AssistantMessage($prefixed)
                    : new UserMessage($prefixed);
            })
            ->filter()
            ->values()
            ->toArray();

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

    private function entityIdentityLabel(): string
    {
        if (method_exists($this->entity, 'people') && $this->entity->people) {
            $name = (string) $this->entity->people->getName();
            if ($name !== '') {
                return "Lead - {$name}";
            }
        }

        return class_basename($this->entity) . ':' . $this->entity->getKey();
    }

    #[Override]
    protected function onNewMessage(Message $message): void
    {
        $role = $message->getRole();

        if (! in_array($role, [MessageRole::USER->value, MessageRole::ASSISTANT->value], true)) {
            return;
        }

        $content = (string) $message->getContent();

        if ($content === '') {
            return;
        }

        $isAssistant = $role === MessageRole::ASSISTANT->value;
        $verb = $isAssistant ? self::AGENT_VERB : self::USER_VERB;
        $messageType = MessageTypeService::getOrCreate($this->app, $verb);

        $messageData = [
            'content' => $content,
            'from_me' => $isAssistant,
            'from_ia' => $isAssistant,
            'from_human' => ! $isAssistant,
        ];

        if ($this->threadId !== null) {
            $messageData['thread_id'] = $this->threadId;
        }

        $createMessageAction = new CreateMessageAction(
            new MessageInput(
                app: $this->app,
                company: $this->company,
                user: $this->user,
                type: $messageType,
                message: $messageData,
                is_public: 0,
            )
        );
        $createMessageAction->runWorkflow = false;
        $socialMessage = $createMessageAction->execute();
        $socialMessage->addEntity($this->entity);
    }
}
