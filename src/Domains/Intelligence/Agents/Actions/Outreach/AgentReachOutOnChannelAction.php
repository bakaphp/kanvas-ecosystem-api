<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Outreach;

use Kanvas\Connectors\RespondIO\Enums\MessageTypeEnum as RespondIoMessageTypeEnum;
use Kanvas\Connectors\Twilio\Enums\MessageTypeEnum as TwilioMessageTypeEnum;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\LeadChannelService;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\DataTransferObject\AiChatMessagePayload;
use Kanvas\Social\Enums\ChannelCategoryEnum;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
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
