<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Stores;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Services\PeopleChannelService;
use Kanvas\Guild\Leads\Enums\LeadMessageTypeEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\LeadChannelService;
use Kanvas\Intelligence\Agents\Services\AttachmentDescriptionService;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\AppModuleMessage;
use Kanvas\Social\Messages\Models\Message as SocialMessage;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\Users\Models\Users;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use Override;

/**
 * Loads history via entity-keyed AppModuleMessage lookup (polymorphic many-to-many) rather than
 * reading from the channel pivot. Chosen so the agent's memory rolls up every comms channel for the
 * same Lead/People — AI chat + Mailgun emails + Twilio SMS + WhatsApp etc. — into one timeline.
 *
 * The thread id the agent passes is never a load filter here: on a channel it is the entity uuid, and
 * narrowing by it would hide every row written before the thread was bound. Only a session-shaped
 * thread (userChat) narrows the load, through $sessionThreadId.
 *
 * TODO(revisit): with the People + Lead channels now populated and backfilled by
 * Pe/LeadChannelService, this loader could switch to reading directly from the People channel pivot
 * for a simpler single-source-of-truth model. Cost of switching: lose external-connector message
 * rollup (anything attached via addEntity but never put in a channel).
 */
class EntityRollupMessageStore extends KanvasMessageStore
{
    private const string AGENT_VERB = 'agent';
    private const string USER_VERB = 'user';

    // Verbs never exposed to the lead
    private const array INTERNAL_VERBS = [
        LeadMessageTypeEnum::NOTES->value,
        LeadMessageTypeEnum::AI_ASSIST->value,
        LeadMessageTypeEnum::INTERNAL->value,
        LeadMessageTypeEnum::CONVERSATION_SUMMARY->value,
    ];

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly Users $user,
        private readonly Model $entity,
        private readonly ?string $sessionThreadId = null,
        private readonly bool $includeInternal = false,
        private readonly ?Lead $currentLead = null,
    ) {
    }

    /**
     * @return list<Message>
     */
    #[Override]
    public function loadActive(string $threadId): array
    {
        $rawRows = AppModuleMessage::query()
            ->where('system_modules', get_class($this->entity))
            ->where('entity_id', $this->entity->getKey())
            ->where('apps_id', $this->app->getId())
            ->whereHas('message', fn ($q) => $q->where('is_deleted', 0))
            ->with(['message.messageType', 'message.channels'])
            ->orderBy('id', 'asc')
            ->get();

        $leadTitleByMessage = $this->buildLeadTitleByMessage(
            $rawRows->pluck('message_id')->filter()->unique()->all(),
        );

        return $rawRows
            ->map(function (AppModuleMessage $appModuleMessage) use ($leadTitleByMessage): ?Message {
                $socialMessage = $appModuleMessage->message;

                if (! $socialMessage) {
                    return null;
                }

                $stored = $socialMessage->getMessage();

                // PersistChatTurnToSocialAction writes the session UUID under `session_id`; persist()
                // writes it under `thread_id`. Both refer to the same value — accept either so
                // promoted-session history loads the full pre-promote conversation.
                $messageThreadId = $stored['thread_id'] ?? $stored['session_id'] ?? null;
                if ($this->sessionThreadId !== null && $messageThreadId !== $this->sessionThreadId) {
                    return null;
                }

                $verb = $socialMessage->messageType?->verb ?? self::USER_VERB;

                if (! $this->includeInternal && in_array($verb, self::INTERNAL_VERBS, true)) {
                    return null;
                }

                $text = (string) ($stored['content'] ?? $stored['text'] ?? '');

                $fromIa = (bool) ($stored['from_ia'] ?? false);
                $fromHuman = (bool) ($stored['from_human'] ?? false);

                // Inbound attachments live in the message JSON (userChat) or as attached files
                // (channel). Surface them as a text marker so an attachment-only turn survives and
                // the agent "remembers" what was sent on later, text-only history rebuilds.
                $marker = $fromIa ? '' : $this->buildAttachmentMarker($stored, $text, $socialMessage);

                if ($text === '' && $marker === '') {
                    return null;
                }

                $leadPrefix = $this->buildLeadPrefix(
                    $leadTitleByMessage[$socialMessage->getId()] ?? null,
                );

                if ($fromIa) {
                    $clean = preg_replace('/^(\[Assistant\]\s*)+/', '', $text) ?? $text;

                    return new AssistantMessage($leadPrefix . $clean)->setId('social:' . $socialMessage->getId());
                }

                $text = trim($text . ($marker !== '' ? "\n" . $marker : ''));
                $channel = $socialMessage->channels->first();

                if ($channel?->isNoteChannel() || $channel?->isAiAssistChannel()) {
                    $prefixed = "[INTERNAL - {$channel->name}] {$text}";
                } elseif ($fromHuman) {
                    $owner = $socialMessage->user?->displayname ?: 'Owner';
                    $prefixed = "[Owner - {$owner}] {$text}";
                } else {
                    $prefixed = '[' . $this->entityIdentityLabel() . "] {$text}";
                }

                return new UserMessage($leadPrefix . $prefixed)->setId('social:' . $socialMessage->getId());
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Persists ONLY tool-call / tool-result telemetry. The conversational rows (user prompt + assistant
     * reply) are written, and attached to this same Lead/People entity, by the canonical writer of every
     * path that uses this store: connector createMessage(), PersistChatTurnToSocialAction (userChat),
     * FollowUp persistMessage(), and the outreach action. A second copy of the reply reaches the model
     * as `reply\n\nreply` and it learns to repeat itself (the duplicate-email loop); tool telemetry is the
     * only thing no other writer captures.
     */
    #[Override]
    protected function persist(string $threadId, Message $message): void
    {
        if (! self::isConversationTurn($message)) {
            return;
        }

        $isToolCall = $message instanceof ToolCallMessage;
        $isToolResult = $message instanceof ToolResultMessage;

        if (! $isToolCall && ! $isToolResult) {
            return;
        }

        $isAssistant = $message->getRole() === MessageRole::ASSISTANT->value;
        $verb = $isAssistant ? self::AGENT_VERB : self::USER_VERB;
        $messageType = MessageTypeService::getOrCreate($this->app, $verb);

        $messageData = [
            'content' => (string) ($message->getContent() ?? ''),
            'from_me' => $isAssistant,
            'from_ia' => $isAssistant,
            'from_human' => ! $isAssistant,
            'thread_id' => $this->sessionThreadId ?? $threadId,
        ];

        $calls = self::serializeCalls($message->getToolCalls());

        if ($isToolCall) {
            $messageData['tool_calls'] = $calls;
        }

        if ($isToolResult) {
            $messageData['tool_results'] = $calls;
        }

        if ($usage = $message->getUsage()) {
            $messageData['usage'] = $usage->jsonSerialize();
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
     * Bulk-fetch the Lead title for each message_id so a People-scoped history can prefix every turn
     * with [Lead: <title>] — lets the LLM separate cross-deal threads. No-op on Lead-scoped sessions.
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

    /**
     * Prefers the descriptions backfilled by DescribeMessageAttachmentsJob (`attachment_descriptions`);
     * falls back to the raw `images` URL list (userChat) and, only when the turn would otherwise vanish,
     * the attached files (channel inbound) — the file lookup is gated to avoid an N+1 across the whole
     * history. Probe via the `files` relation, not `getFiles()`: the latter hands back
     * FilesystemEntities rows, which `isDescribableFile()` rejects with a TypeError.
     */
    private function buildAttachmentMarker(array $stored, string $text, SocialMessage $socialMessage): string
    {
        $descriptions = array_values(array_filter(
            (array) ($stored['attachment_descriptions'] ?? []),
            static fn (mixed $d): bool => is_string($d) && trim($d) !== '',
        ));

        if ($descriptions !== []) {
            return implode(' ', array_map(static fn (string $d): string => "[Attachment: {$d}]", $descriptions));
        }

        $images = (array) ($stored['images'] ?? []);

        if ($images !== []) {
            return trim(str_repeat('[Attachment] ', count($images)));
        }

        if ($text === '' && $socialMessage->files->contains(
            fn (Filesystem $file): bool => AttachmentDescriptionService::isDescribableFile($file)
        )) {
            return '[Attachment]';
        }

        return '';
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
