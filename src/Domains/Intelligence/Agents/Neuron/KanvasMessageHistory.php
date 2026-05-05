<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Messages\Models\AppModuleMessage;
use Kanvas\Users\Models\Users;
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
        private readonly bool $includeInternal = false, // ← nuevo
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

                $verb = $socialMessage->messageType?->verb ?? self::USER_VERB;

                // Si no incluimos internos, filtramos los verbos internos
                if (! $this->includeInternal && in_array($verb, self::INTERNAL_VERBS)) {
                    return null;
                }

                $text = $stored['text'] ?? '';

                // Mensajes internos se marcan con prefijo para que el agente entienda el contexto
                if (in_array($verb, self::INTERNAL_VERBS)) {
                    return new UserMessage("[INTERNAL - {$verb}]: {$text}");
                }

                return in_array($verb, [self::AGENT_VERB, 'assistant', 'ai', 'bot'])
                    ? new AssistantMessage($text)
                    : new UserMessage($text);
            })
            ->filter()
            ->values()
            ->toArray();

        if (! empty($messages)) {
            $this->history = $messages;
        }
    }
}
