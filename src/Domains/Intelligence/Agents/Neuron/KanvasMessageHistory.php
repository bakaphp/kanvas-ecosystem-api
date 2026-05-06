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
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;

class KanvasMessageHistory extends AbstractChatHistory
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

                $channel = $socialMessage->channels()->first();

                $isInternal = $channel->isNoteChannel() || $channel->isAiAssistChannel();

                $verb = $socialMessage->messageType?->verb ?? self::USER_VERB;

                if (! $this->includeInternal && in_array($verb, self::INTERNAL_VERBS)) {
                    return null;
                }

                $text = $stored['content'] ?? $stored['text'] ?? '';

                if ($isInternal) {
                    return new UserMessage("[INTERNAL - {$channel->name}]: {$text}");
                } elseif ($socialMessage->from_human) {
                    $text = "[Owner - $socialMessage->user->displayname] $text";
                } else {
                    $text = "[Assistant] $text";
                }
                $neuronMessage = $socialMessage->from_ia ?
                        new AssistantMessage($text)
                        : new UserMessage($text);

                return $neuronMessage;
            })
            ->filter()
            ->values()
            ->toArray();

        if (! empty($messages)) {
            $this->history = $messages;
        }
    }

    protected function onNewMessage(Message $message): void
    {
        if ($message->getRole() !== MessageRole::ASSISTANT->value) {
            return;
        }

        $messageType = MessageTypeService::getOrCreate($this->app, self::AGENT_VERB);

        $messageData = [
            'content' => $message->getContent(),
            'from_me' => true,
            'from_ia' => true,
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
