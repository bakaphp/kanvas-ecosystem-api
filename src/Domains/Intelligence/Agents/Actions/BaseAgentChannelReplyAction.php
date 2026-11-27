<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Baka\Support\DateHelper;
use Baka\Support\Str;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Actions\Communication\MarkLeadMessagesAsRespondedAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\LeadChannelService;
use Kanvas\Guild\Leads\Services\NotifyLeadStakeholdersService;
use Kanvas\Intelligence\Agents\Exceptions\AgentReplySkippedException;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Traits\DispatchesAttachmentDescriptionTrait;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\FollowUp\Services\LeadFollowUpEligibilityService;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\Actions\RequestMessageApprovalAction;
use Kanvas\Social\Messages\DataTransferObject\AiChatMessagePayload;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Support\MessageApproval;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Throwable;

/**
 * Shared base for per-connector channel reply actions (WaSender, Mailgun, RespondIO,
 * Twilio, Microsoft, SalesAssist). Owns AI-mode/responded guards, outbound message
 * persistence via createMessage(), message-type-verb tagging, and the
 * MarkLeadMessagesAsResponded + NotifyLeadStakeholders side effects.
 *
 * Subclasses' execute() extracts the inbound text, calls AgentChatKernel for the
 * agent's reply, then persists + ships outbound via this base's createMessage()
 * and their connector-specific outbound client.
 */
class BaseAgentChannelReplyAction
{
    use DispatchesAttachmentDescriptionTrait;

    protected string $messageTypeVerb = 'text';
    protected string $communicationChannel = '';
    protected bool $supportsHumanApproval = false;
    protected bool $respectsLeadAiMode = true;
    protected bool $mirrorsReplyToLeadChannels = true;

    public function __construct(
        protected Channel $channel,
        protected Message $message,
        protected Agent $agent,
        protected ?Session $session = null,
    ) {
        $lead = $this->session?->entity() ?? $this->message->preferredEntity(Lead::class);

        if ($lead === null) {
            throw new Exception('No lead found for AI agent');
        }

        // Inbound from a lead the agent gave up on → resume the follow-up cycle.
        // Only `agent:` exhaustions auto-resume; handoff / max_retries / pipeline_completed
        // stay terminal and require an explicit operator reset.
        if ($lead instanceof Lead && $lead->isFollowUpExhausted()) {
            $reason = $lead->getFollowUpExhaustedReason();
            if (is_string($reason) && str_starts_with($reason, 'agent:')) {
                $lead->resumeFollowUp();
                $lead->emitLedgerEvent('lead.follow_up.resumed', payload: [
                    'stage_id' => $lead->pipeline_stage_id,
                    'reason' => 'inbound_reply',
                ]);
            }
        }

        // A person's plan ends on the reply itself, not an hour later at the next pass.
        if ($lead instanceof Lead) {
            $inboundAt = DateHelper::tryParseCarbon($this->message->created_at) ?? Carbon::now();
            new LeadFollowUpEligibilityService()->concludeRepliedPlan($lead, $inboundAt);
        }

        if ($this->respectsLeadAiMode) {
            // A conversation's session can be the person's, with the lead only on the message. The
            // mode is applied to both (ApplyAiModeAction), and off on either one silences the turn.
            $messageLead = $lead instanceof Lead ? $lead : $this->message->entity(Lead::class);
            $isAiMuted = match (true) {
                $lead instanceof People => $lead->isAiMuted($messageLead),
                $messageLead instanceof Lead => $messageLead->isAiMuted(),
                default => IntelligenceModeEnum::tryFrom((string) $lead->get('ai_mode'))?->isOff() ?? false,
            };

            if ($isAiMuted) {
                throw new AgentReplySkippedException('Ai Agent Off for this lead');
            }
        }

        if ($message->is_un_response) {
            throw new AgentReplySkippedException('Message is responded previous');
        }

        $this->dispatchAttachmentDescription($this->message, $this->agent, $this->channel);
    }

    /**
     * @param list<int> $burstMessageIds every message of the closed burst this turn answers
     *
     * @return array{text: string, images: list<string>, documents: list<string>}
     */
    protected function inboundContent(?string $text, array $burstMessageIds = []): array
    {
        $media = self::mediaOf($this->burst($burstMessageIds));
        $text = Str::trimToNull($text) ?? '';

        // Gemini requires a non-empty text part alongside attachments (KANVAS-ECOSYSTEM-6QC).
        if ($text === '' && ($media['images'] !== [] || $media['documents'] !== [])) {
            $text = 'The customer sent an attachment without any text. Look at it and answer about it.';
        }

        return ['text' => $text, ...$media];
    }

    /**
     * The messages this turn answers: this one, or every message of the closed burst it opens. A
     * photo sent after the words is part of the same turn, and the agent leaves the burst's rows out
     * of the history it loads, so an attachment read only from this message is not seen at all.
     *
     * @param list<int> $burstMessageIds
     *
     * @return Collection<int, Message>
     */
    protected function burst(array $burstMessageIds): Collection
    {
        if ($burstMessageIds === []) {
            return new Collection([$this->message]);
        }

        return $this->channel->messages()
            ->whereIn('messages.id', [$this->message->getId(), ...$burstMessageIds])
            ->orderBy('messages.id')
            ->get();
    }

    /**
     * @param Collection<int, Message> $messages
     *
     * @return array{images: list<string>, documents: list<string>}
     */
    protected static function mediaOf(Collection $messages): array
    {
        $images = [];
        $documents = [];

        foreach ($messages as $message) {
            $urls = $message->attachmentUrls();
            $images = [...$images, ...$urls['images']];
            $documents = [...$documents, ...$urls['documents']];
        }

        return [
            'images' => array_values(array_unique($images)),
            'documents' => array_values(array_unique($documents)),
        ];
    }

    /**
     * @param string|null $rawResponse the agent's untouched reply, before ChatHelper picked the
     *                                 outbound text out of it. An agent that answers with a whole
     *                                 record — a post, a quote, an enrichment — loses every field
     *                                 but the body once the text is picked, so the decoded envelope
     *                                 is kept on the message as `response_json` for later activities.
     */
    protected function createMessage(
        string $text,
        string $to,
        Message $message,
        Channel $channel,
        ?string $from = null,
        ?string $rawResponse = null
    ): Message {
        if (trim($text) === '') {
            throw new AgentReplySkippedException('Empty message was created');
        }
        $user = $this->channel->company->getAiAgentUser() ?? $message->user;
        $type = $this->getMessageType($message->app);
        $structuredResponse = $rawResponse !== null ? ChatHelper::extractJsonEnvelope($rawResponse) : null;
        $messageInput = new MessageInput(
            app: $message->app,
            company: $message->company,
            user: $user,
            message: [
                ...AiChatMessagePayload::from([
                    'content' => $text,
                    'from_me' => true,
                    'from_ia' => true,
                    'session_id' => $this->session?->uuid,
                    'agent_id' => (int) $this->agent->getId(),
                    'raw_data' => $text,
                    'message_id' => '--',
                    'chat_jid' => $to,
                ])->toArray(),
                ...($structuredResponse !== null ? ['response_json' => $structuredResponse] : []),
            ],
            is_public: 1,
            tags: [$to],
            type: $type,
            //slug: Str::slug($text) . '-' . microtime()
        );

        $newMessage = new CreateMessageAction($messageInput);
        $newMessage->runWorkflow = false;
        $newMessage = $newMessage->execute();

        $newMessage->set('communicationChannel', $this->communicationChannel);
        $newMessage->set('from_number', $from);

        $this->carryForwardAttachments($message, $newMessage);

        $entities = $message->entities();
        foreach ($entities as $entity) {
            $newMessage->addEntity($entity);
        }

        // People-keyed history (EntityRollupMessageStore) queries by People;
        // without this attachment the outbound disappears from cross-channel rollup
        // and from the People profile UI.
        $lead = $entities->first(fn (Model $entity) => $entity instanceof Lead);
        if ($lead?->people !== null && ! $entities->contains(fn (Model $entity) => $entity instanceof People)) {
            $newMessage->addEntity($lead->people);
        }

        // Company in APPROVAL mode → hold as a draft and open an approval; a human signs off before
        // it ships. Scoped to connectors that implement the approve→send path ($supportsHumanApproval).
        // Not private: is_public stays 1 so the draft shows in the reviewer's own feed, which is where
        // they find it.
        if ($this->supportsHumanApproval && $this->companyRequiresHumanApproval()) {
            new RequestMessageApprovalAction(
                message: $newMessage,
                kind: MessageApproval::KIND_EMAIL_DRAFT,
                private: false,
            )->execute();
        }

        $newMessage->fireWorkflow(
            WorkflowEnum::CREATED->value,
            true,
            [
                 'app' => $newMessage->app,
             ]
        );
        $channel->addMessage($newMessage);

        if ($lead instanceof Lead) {
            new MarkLeadMessagesAsRespondedAction($lead, $newMessage, $message)->execute();
            new NotifyLeadStakeholdersService($lead)->onAgentReply($newMessage, isHuman: false);

            // ADK is left alone: its remote memory depends on the per-protocol channel only.
            // Internal channels (the rep talking to the agent about the lead) opt out through
            // $mirrorsReplyToLeadChannels: that chatter is not a delivery and must not land in
            // the customer-facing lead conversation.
            if ($this->mirrorsReplyToLeadChannels && ! $this->agent->isAdk()) {
                new LeadChannelService()->recordOnLeadAndPerson($newMessage, $lead, $user);
            }
        }

        $this->stampReplyOnMemory($message, $newMessage);

        return $newMessage;
    }

    /**
     * The turn wrote its memory before this reply row existed; give it the reply's id so recall can
     * tell when the exchange is still in the window. Recall works without it, so a failure is logged.
     */
    private function stampReplyOnMemory(Message $inbound, Message $reply): void
    {
        $app = $this->agent->app;

        if (! KnowledgeComponents::memoryEnabled($app)) {
            return;
        }

        try {
            KnowledgeComponents::store($app)->stampReplyMessageId(
                (int) $app->getId(),
                (int) $reply->companies_id,
                (int) $inbound->getId(),
                (int) $reply->getId(),
            );
        } catch (Throwable $e) {
            Log::warning('Could not stamp the reply id on the turn memory', [
                'inbound_message_id' => $inbound->getId(),
                'reply_message_id' => $reply->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The sender's attachments land on the inbound message, but everything downstream reads the reply
     * — the WordPress publisher skips inbound messages outright, so an emailed-in photo could never
     * become a post's featured image. Same Filesystem rows, not copies.
     *
     * Must stay ahead of fireWorkflow() so the rules the reply triggers already see the files.
     */
    private function carryForwardAttachments(Message $inbound, Message $reply): void
    {
        foreach ($inbound->files as $file) {
            try {
                $reply->addFile($file, (string) $file->name);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    protected function companyRequiresHumanApproval(): bool
    {
        return $this->message->company->requiresAgentHumanApproval();
    }

    protected function hijackMessagePhone(string $channelId): string
    {
        if ($this->agent->company->get('allow_session_hijack', false)
          && $this->agent->company->get('overwrite_phone_number') !== null
        ) {
            $overwriteConfig = $this->agent->company->get('overwrite_phone_number');
            $originalRemoteJid = $channelId;

            // Reverse lookup: hijacked -> original
            $reverseMapping = array_flip($overwriteConfig);
            if (isset($reverseMapping[$originalRemoteJid])) {
                return $reverseMapping[$originalRemoteJid];
            }
        }

        return $channelId;
    }

    public function execute(array $params = []): array
    {
        return [];
    }

    public function getMessageType(Apps $app): MessageType
    {
        return MessageTypeService::getOrCreate($app, $this->messageTypeVerb);
    }
}
