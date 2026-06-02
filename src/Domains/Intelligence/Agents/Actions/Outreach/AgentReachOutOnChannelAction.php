<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Outreach;

use Kanvas\Guild\Customers\Services\PeopleChannelService;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\LeadChannelService;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\DataTransferObject\AiChatMessagePayload;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\Workflow\Enums\WorkflowEnum;

/**
 * Bootstrap channel + session for a Lead on one channel type, generate the reach-out
 * text via AgentChatKernel, persist as an outbound Message, and ship via
 * SendMessageToLeadAction. Returns the persisted outbound Message.
 *
 * Outbound-first sibling of the inbound BaseAgentChannelReplyAction shape: persists
 * exactly once with from_ia=true + session_id tagged, attaches to channel + lead,
 * fires the CREATED workflow so the normal post-create chain runs.
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
        $company = $this->lead->company;
        $aiAgentUser = $company->getAiAgentUserOrFail();

        [$channel, $session] = new BootstrapChannelForLeadAction(
            $this->lead,
            $this->channelType,
            $this->recipient,
            $this->agent,
        )->execute();

        // The agent's role / soul / instructions / output_format columns are the SYSTEM
        // prompt — they define what the agent IS and how it generates the first message.
        // The user-turn below is just the ACTION trigger: tells the agent to act now,
        // names the channel for output sizing, and hints the canonical tool call.
        $responseContent = new AgentChatKernel(
            agent: $this->agent,
            session: $session,
            message: sprintf(
                'Send the first reach-out %s message to this lead now. Use get_lead_ref to load context.',
                $this->channelType,
            ),
            user: $aiAgentUser,
            currentLead: $this->lead,
            sourceChannel: $channel,
            sourceMessage: null,
            persistConversation: false,
        )->execute();

        $responseText = ChatHelper::extractTextFromResponse($responseContent);

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
        $channel->addMessage($outbound);

        // Canonical sales-agent pattern: one message lives on three channels.
        //   1. per-recipient slug channel (above) — for inbound webhook routing
        //   2. lead-channel-{id}            — deal-scoped CRM UI surface
        //   3. people-channel-{id}          — durable cross-Lead conversation master
        // The services handle channel attach + addEntity idempotently.
        $leadChannelService = new LeadChannelService();
        $leadChannelService->attachMessageToLeadChannel(
            $outbound,
            $this->lead,
            $this->lead->app,
            $company,
            $aiAgentUser,
        );

        if ($this->lead->people !== null) {
            new PeopleChannelService()->attachMessageToPeopleChannel(
                $outbound,
                $this->lead->people,
                $this->lead->app,
                $company,
                $aiAgentUser,
            );
        }

        $outbound->fireWorkflow(
            WorkflowEnum::CREATED->value,
            true,
            ['app' => $this->lead->app],
        );

        // Deliver via the canonical Lead-level dispatcher (SMS/email/WhatsApp/voice).
        new SendMessageToLeadAction($this->lead)->execute(
            channel: $this->channelType,
            message: $responseText,
            from: null,
            title: null,
            signature: false,
            files: null,
            to: $this->recipient,
        );

        return $outbound;
    }

    /**
     * Pick the canonical message-type verb that the inbound responder for this
     * channel also uses, so memory history loaders find them by the same verb.
     */
    private function resolveMessageTypeVerb(): string
    {
        return match ($this->channelType) {
            'whatsapp' => 'whatsapp',
            'sms' => 'twilio-sms',
            'email' => 'mailgun-email',
            'respondio' => 'respondio-text',
            default => 'text',
        };
    }
}
