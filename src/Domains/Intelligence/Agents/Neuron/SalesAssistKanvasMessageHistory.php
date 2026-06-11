<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Services\PeopleChannelService;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\LeadChannelService;
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
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolInterface;
use Override;

/**
 * Loads chat history via entity-keyed AppModuleMessage lookup (polymorphic
 * many-to-many) rather than reading from the channel pivot. Chosen so the
 * agent's memory rolls up every comms channel for the same Lead/People —
 * AI chat + Mailgun emails + Twilio SMS + WhatsApp etc. — into one timeline.
 *
 * TODO(revisit): with the People + Lead channels now populated and backfilled
 * by Pe/LeadChannelService, this loader could switch to reading directly from
 * the People channel pivot for a simpler single-source-of-truth model. Cost
 * of switching: lose external-connector message rollup (anything attached
 * via addEntity but never put in a channel). Worth a fresh look once the
 * channel population is proven stable in prod.
 */
class SalesAssistKanvasMessageHistory extends AbstractChatHistory
{
    private const string AGENT_VERB = 'agent';
    private const string USER_VERB = 'user';

    // Verbs never expose to the lead
    private const array INTERNAL_VERBS = ['notes', 'ai_assist', 'internal'];

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly Users $user,
        private readonly Model $entity,
        private readonly ?string $threadId = null,
        private readonly bool $includeInternal = false,
        private readonly ?Lead $currentLead = null,
        int $contextWindow = 50000,
    ) {
        parent::__construct($contextWindow);
        $this->load();
    }

    private function load(): void
    {
        /**
         * @todo move this to channels
         */
        $rawRows = AppModuleMessage::query()
            ->where('system_modules', get_class($this->entity))
            ->where('entity_id', $this->entity->getKey())
            ->where('apps_id', $this->app->getId())
            ->whereHas('message', fn ($q) => $q->where('is_deleted', 0))
            ->with(['message.messageType'])
            ->orderBy('id', 'asc')
            ->get();

        $leadTitleByMessage = $this->buildLeadTitleByMessage(
            $rawRows->pluck('message_id')->filter()->unique()->all(),
        );

        $messages = $rawRows
            ->map(function (AppModuleMessage $appModuleMessage) use ($leadTitleByMessage): ?Message {
                $socialMessage = $appModuleMessage->message;

                if (! $socialMessage) {
                    return null;
                }

                $stored = $socialMessage->getMessage();

                // PersistChatTurnToSocialAction writes the session UUID under
                // `session_id`; onNewMessage writes it under `thread_id`. Both
                // refer to the same value — accept either so promoted-session
                // history loads the full pre-promote conversation.
                $messageThreadId = $stored['thread_id'] ?? $stored['session_id'] ?? null;
                if ($this->threadId !== null && $messageThreadId !== $this->threadId) {
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

                $leadPrefix = $this->buildLeadPrefix(
                    $leadTitleByMessage[$socialMessage->getId()] ?? null,
                );

                if ($fromIa) {
                    $clean = preg_replace('/^(\[Assistant\]\s*)+/', '', $text) ?? $text;

                    return new AssistantMessage($leadPrefix . $clean);
                }

                if ($isInternal) {
                    $prefixed = "[INTERNAL - {$channel->name}] {$text}";
                } elseif ($fromHuman) {
                    $owner = $socialMessage->user?->displayname ?: 'Owner';
                    $prefixed = "[Owner - {$owner}] {$text}";
                } else {
                    $prefixed = '[' . $this->entityIdentityLabel() . "] {$text}";
                }

                return new UserMessage($leadPrefix . $prefixed);
            })
            ->filter()
            ->values()
            ->toArray();

        $coalesced = [];
        foreach ($messages as $m) {
            $last = end($coalesced) ?: null;
            if ($last !== null && $last->getRole() === $m->getRole()) {
                $last->setContents(
                    self::coalesceContent(
                        (string) $last->getContent(),
                        (string) $m->getContent()
                    )
                );

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
            $last->setContents(
                self::coalesceContent(
                    (string) $last->getContent(),
                    (string) $message->getContent()
                )
            );
            $this->trimHistory();
            $this->onNewMessage($message);
            $this->setMessages($this->history);

            return $this;
        }

        return parent::addMessage($message);
    }

    /**
     * Merge two consecutive same-role messages while refusing to duplicate.
     *
     * Providers (Anthropic) require strict user/assistant alternation, so consecutive
     * same-role turns must collapse into one. But each turn is persisted twice against
     * the entity — once by this history's onNewMessage(), once by the connector's
     * official outbound — so the two rows are identical. Concatenating them produced
     * `"reply\n\nreply"` in the model's context, which the model then imitated by
     * emitting its own replies twice (the duplicate-email feedback loop). Keep the
     * longer copy when one already contains the other; only genuinely different turns
     * concatenate.
     */
    private static function coalesceContent(string $existing, string $incoming): string
    {
        $a = trim($existing);
        $b = trim($incoming);

        if ($b === '' || $a === $b || ($a !== '' && str_contains($a, $b))) {
            return $existing;
        }

        if ($a === '' || str_contains($b, $a)) {
            return $incoming;
        }

        return $existing . "\n\n" . $incoming;
    }

    private function entityIdentityLabel(): string
    {
        if (method_exists($this->entity, 'people') && $this->entity->people) {
            $name = (string) $this->entity->people->getName();
            if ($name !== '') {
                return "Lead - {$name}";
            }
        }

        if ($this->entity instanceof People) {
            $name = (string) $this->entity->getName();
            if ($name !== '') {
                return "Prospect - {$name}";
            }
        }

        return class_basename($this->entity) . ':' . $this->entity->getKey();
    }

    /**
     * Bulk-fetch the Lead title for each message_id so a People-scoped history
     * can prefix every turn with [Lead: <title>] — lets the LLM separate
     * cross-deal threads. No-op on Lead-scoped sessions.
     *
     * @param list<int> $messageIds
     * @return array<int, string> message_id => lead title
     */
    private function buildLeadTitleByMessage(array $messageIds): array
    {
        if ($messageIds === [] || ! ($this->entity instanceof People)) {
            return [];
        }

        $messageToLeadId = AppModuleMessage::query()
            ->whereIn('message_id', $messageIds)
            ->where('system_modules', Lead::class)
            ->pluck('entity_id', 'message_id')
            ->all();

        if ($messageToLeadId === []) {
            return [];
        }

        $leadTitles = Lead::query()
            ->whereIn('id', array_unique(array_values($messageToLeadId)))
            ->pluck('title', 'id')
            ->all();

        $out = [];
        foreach ($messageToLeadId as $messageId => $leadId) {
            if (isset($leadTitles[$leadId])) {
                $out[(int) $messageId] = (string) $leadTitles[$leadId];
            }
        }

        return $out;
    }

    private function buildLeadPrefix(?string $leadTitle): string
    {
        if ($leadTitle === null || $leadTitle === '' || ! ($this->entity instanceof People)) {
            return '';
        }

        return "[Lead: {$leadTitle}] ";
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

        // Persist ONLY tool-call / tool-result telemetry here. The conversational
        // content rows (user prompt + assistant reply) are already written — and
        // attached to this same Lead/People entity — by the canonical writers for every
        // path that uses this history: connector createMessage(), PersistChatTurnToSocialAction
        // (userChat), FollowUp persistMessage(), and the outreach action. Writing them again
        // produced a second from_ia row per turn, which the loader coalesced into the model's
        // context as `reply\n\nreply` — and the model learned to repeat itself (duplicate-email
        // loop). Tool telemetry is the only thing no other writer captures, so keep just that.
        if (! $isToolCall && ! $isToolResult) {
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

        if ($isToolCall) {
            $messageData['tool_calls'] = array_map(
                fn (ToolInterface $tool): array => $tool->jsonSerialize(),
                $message->getTools(),
            );
        }

        if ($isToolResult) {
            $messageData['tool_results'] = array_map(
                fn (ToolInterface $tool): array => $tool->jsonSerialize(),
                $message->getTools(),
            );
        }

        if ($usage = $message->getUsage()) {
            $messageData['usage'] = $usage->jsonSerialize();
        }

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

        $people = $this->resolvePeopleForDualWrite();
        if ($people !== null) {
            new PeopleChannelService()->attachMessageToPeopleChannel(
                $socialMessage,
                $people,
                $this->app,
                $this->company,
                $this->user,
            );
        }

        $lead = $this->resolveLeadForDualWrite();
        if ($lead !== null) {
            new LeadChannelService()->attachMessageToLeadChannel(
                $socialMessage,
                $lead,
                $this->app,
                $this->company,
                $this->user,
            );
        }
    }

    private function resolveLeadForDualWrite(): ?Lead
    {
        if ($this->entity instanceof Lead) {
            return $this->entity;
        }

        return $this->currentLead;
    }

    private function resolvePeopleForDualWrite(): ?People
    {
        if ($this->entity instanceof People) {
            return $this->entity;
        }

        if ($this->entity instanceof Lead) {
            return $this->entity->people;
        }

        return null;
    }
}
