<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Services\PeopleChannelService;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\LeadChannelService;
use Kanvas\Guild\Leads\Services\NotifyLeadStakeholdersService;
use Kanvas\Intelligence\Agents\Exceptions\AgentReplySkippedException;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Traits\DispatchesAttachmentDescriptionTrait;
use Kanvas\Intelligence\Agents\Types\ADKAgent;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\Actions\MarkLeadMessagesAsRespondedAction;
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

    public function __construct(
        protected Channel $channel,
        protected Message $message,
        protected Agent $agent,
        protected ?Session $session = null,
    ) {
        $lead = $this->session?->entity() ?? $this->message->entity();

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

        if ($this->respectsLeadAiMode) {
            $isAiMuted = $lead instanceof Lead
                ? $lead->isAiMuted()
                : IntelligenceModeEnum::tryFrom((string) $lead->get('ai_mode'))?->isOff() ?? false;

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
        if (empty($text)) {
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

        $entity = $message->entity();
        if ($entity instanceof Model) {
            $newMessage->addEntity($entity);
            // People-keyed history (SalesAssistKanvasMessageHistory) queries by People;
            // without this attachment the outbound disappears from cross-channel rollup
            // and from the People profile UI.
            if ($entity instanceof Lead && $entity->people !== null) {
                $newMessage->addEntity($entity->people);
            }
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

        $lead = $message->entity();
        if ($lead instanceof Lead) {
            new MarkLeadMessagesAsRespondedAction($lead, $newMessage)->execute();
            new NotifyLeadStakeholdersService($lead)->onAgentReply($newMessage, isHuman: false);

            // Non-ADK agents (Neuron / Laravel / Runtime) get the master Lead + People
            // channel dual-write so the CRM deal view and People profile show the actual
            // delivery message (verb='twilio-sms', 'mailgun-email', etc.). ADK is left
            // alone — its remote memory and existing legacy behavior depend on the
            // per-protocol channel only.
            if ($this->agent->type?->handler !== ADKAgent::class) {
                new LeadChannelService()->attachMessageToLeadChannel(
                    $newMessage,
                    $lead,
                    $message->app,
                    $message->company,
                    $user,
                );
                if ($lead->people !== null) {
                    new PeopleChannelService()->attachMessageToPeopleChannel(
                        $newMessage,
                        $lead->people,
                        $message->app,
                        $message->company,
                        $user,
                    );
                }
            }
        }

        return $newMessage;
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
