<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Outreach;

use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\RespondIO\Enums\MessageTypeEnum as RespondIoMessageTypeEnum;
use Kanvas\Connectors\Twilio\Actions\StoreMessageSidAction;
use Kanvas\Connectors\Twilio\Enums\MessageTypeEnum as TwilioMessageTypeEnum;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\LeadChannelService;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Enums\ChannelCategoryEnum;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\Actions\RequestMessageApprovalAction;
use Kanvas\Social\Messages\DataTransferObject\AiChatMessagePayload;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Support\MessageApproval;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\Workflow\Enums\WorkflowEnum;

/**
 * One reach-out turn for one channel: kernel → persist → ship. The outbound
 * lands on three channels (per-recipient slug, lead-channel, people-channel) —
 * canonical sales-agent pattern so the People profile and the deal view both
 * see it. Outbound-first sibling of the inbound BaseAgentChannelReplyAction.
 */
class AgentReachOutOnChannelAction
{
    public function __construct(
        protected readonly Lead $lead,
        protected readonly Agent $agent,
        protected readonly string $channelType,
        protected readonly string $recipient,
    ) {
    }

    public function execute(): Message
    {
        /** @var Companies $company */
        $company = $this->lead->company;
        $aiAgentUser = $company->getAiAgentUserOrFail();

        [$channel, $session] = new BootstrapChannelForLeadAction(
            $this->lead,
            $this->channelType,
            $this->recipient,
            $this->agent,
        )->execute();

        // The agent's role / soul / instructions / output_format columns are the system
        // prompt; this user-turn is just the action trigger.
        $responseContent = new AgentChatKernel(
            agent: $this->agent,
            session: $session,
            message: $this->buildTriggerMessage(),
            user: $aiAgentUser,
            currentLead: $this->lead,
            sourceChannel: $channel,
            sourceMessage: null,
            persistConversation: false,
        )->execute();

        $responseText = ChatHelper::extractTextFromResponse($responseContent);

        // Email needs a real subject as the mail title. Prefer a structured `subject`
        // field from the agent's JSON envelope (what buildTriggerMessage asks for); fall
        // back to a legacy in-body `Subject: ...` line for agents that don't emit it.
        $emailSubject = null;
        if ($this->channelType === ChannelCategoryEnum::EMAIL->value) {
            $emailSubject = ChatHelper::extractSubjectFromResponse($responseContent);

            if ($emailSubject === null) {
                $parsed = ChatHelper::extractEmailSubjectAndBody($responseText);
                $emailSubject = $parsed['subject'];
                $responseText = $parsed['body'];
            }

            // Anchor the email thread: the follow-up engine reuses this subject
            // (as "Re: ...") so later touches thread under the original email
            // instead of starting a fresh one. First touch wins — don't clobber
            // an existing anchor on subsequent reach-outs.
            if (
                $emailSubject !== null
                && $emailSubject !== ''
                && ! $this->lead->get('title_email_follow_up')
            ) {
                $this->lead->set('title_email_follow_up', $emailSubject);
            }
        }

        $messageTypeVerb = $this->resolveMessageTypeVerb();
        $type = MessageTypeService::getOrCreate($this->lead->app, $messageTypeVerb);

        $createMessage = new CreateMessageAction(
            new MessageInput(
                app: $this->lead->app,
                company: $company,
                user: $aiAgentUser,
                message: AiChatMessagePayload::from([
                    'content' => $responseText,
                    'from_me' => true,
                    'from_ia' => true,
                    'session_id' => $session->uuid,
                    'agent_id' => (int) $this->agent->getId(),
                    'raw_data' => $responseText,
                    'message_id' => '--',
                    'chat_jid' => $this->recipient,
                ])->toArray(),
                is_public: 1,
                tags: [$this->recipient, 'agent-reach-out', 'first-touch'],
                type: $type,
            )
        );
        $createMessage->runWorkflow = false;
        $outbound = $createMessage->execute();

        $outbound->set('communicationChannel', $this->channelType);
        // $channel is the master People channel. addMessage() handles the channel pivot;
        // the polymorphic People entity link (used by history loaders) needs an explicit
        // addEntity.
        $channel->addMessage($outbound);
        if ($this->lead->people !== null) {
            $outbound->addEntity($this->lead->people);
        }

        new LeadChannelService()->attachMessageToLeadChannel(
            $outbound,
            $this->lead,
            $this->lead->app,
            $company,
            $aiAgentUser,
        );

        // Company in APPROVAL mode → hold the first touch and open an approval on it.
        if (
            $this->channelType === ChannelCategoryEnum::EMAIL->value
            && $company->requiresAgentHumanApproval()
        ) {
            new RequestMessageApprovalAction(
                message: $outbound,
                kind: MessageApproval::KIND_EMAIL_DRAFT,
                private: false,
            )->execute();
        }

        $outbound->fireWorkflow(
            WorkflowEnum::CREATED->value,
            true,
            ['app' => $this->lead->app],
        );

        // Deliver via the canonical Lead-level dispatcher (SMS/email/WhatsApp/voice),
        // unless the draft is held for human approval.
        if (! $outbound->isLocked()) {
            $providerResponse = new SendMessageToLeadAction($this->lead)->execute(
                channel: $this->channelType,
                message: $responseText,
                from: null,
                title: $emailSubject,
                signature: false,
                files: null,
                to: $this->recipient,
            );
            new StoreMessageSidAction($outbound)->execute($providerResponse);
        }

        return $outbound;
    }

    /**
     * The user-turn that triggers the first reach-out. For email we additionally ask
     * the agent to return a JSON envelope carrying a `subject` so it becomes the mail
     * title instead of being buried in the body. ChatHelper parses the envelope on the
     * way out (and falls back gracefully if the agent ignores the request).
     */
    private function buildTriggerMessage(): string
    {
        $message = sprintf(
            'Send the first reach-out %s message to this lead now. Use get_lead_ref to load context.',
            $this->channelType,
        );

        if ($this->channelType === ChannelCategoryEnum::EMAIL->value) {
            $message .= ' Reply with a JSON object of the form'
                . ' {"subject": "<concise email subject>", "response": "<the email body>"}'
                . ' and nothing else. Do not put the subject line inside the body.';
        }

        return $message;
    }

    /** Match the verb the inbound responder uses so history loaders find both. */
    private function resolveMessageTypeVerb(): string
    {
        return match ($this->channelType) {
            ChannelCategoryEnum::WHATSAPP->value => ChannelCategoryEnum::WHATSAPP->value,
            ChannelCategoryEnum::SMS->value => TwilioMessageTypeEnum::SMS->value,
            ChannelCategoryEnum::EMAIL->value => ChannelCategoryEnum::MAILGUN->value,
            ChannelCategoryEnum::RESPONDIO->value => RespondIoMessageTypeEnum::TEXT->value,
            default => 'text',
        };
    }
}
