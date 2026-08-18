<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Workflows;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Actions\StoreMessageSidAction;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\NotifyLeadStakeholdersService;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Enums\ChannelCategoryEnum;
use Kanvas\Social\Messages\Actions\MarkLeadMessagesAsRespondedAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

/**
 * Outbound channel delivery for messages authored by an EXTERNAL AI / orchestrator.
 *
 * Sibling of {@see \Kanvas\Connectors\Twilio\Workflows\HumanAgentChannelResponseActivity}: same
 * channel routing (SMS / email / WhatsApp, picked from the message verb) and same delivery path
 * (SendMessageToLeadAction), but gated on the external-AI flags `from_ia` / `from_orchestrator`
 * (set on `messages.message`) instead of `from_human` — for now BOTH must be true. The external
 * orchestrator sends `from_me=true`, `from_ia=true`, `from_orchestrator=true`.
 *
 * Unlike the human variant it does NOT fire HUMAN_TAKEOVER / pause the local AI — the author is
 * another AI, not a person stepping in. It only delivers the message, marks the thread responded,
 * and notifies stakeholders as a (non-human) agent reply.
 */
#[WorkflowAction(
    name: 'External Agent Channel Response',
    description: 'Delivers an agent reply that was produced elsewhere back onto the channel it belongs to. '
        . 'Part of the external/async responder path — you rarely wire this by hand.',
)]
class ExternalAgentChannelResponseActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Channel $channel, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);
        $message = $params['message'] ?? null;
        $company = $channel->company;
        $channelContext = [
            'channel_id' => $channel->getId(),
            'channel_uuid' => $channel->uuid ?? null,
            'channel_slug' => $channel->slug ?? null,
            'channel_entity_type' => $channel->entity_namespace ?? null,
            'channel_entity_id' => $channel->entity_id ?? null,
            'app_id' => $app->getId(),
            'company_id' => $channel->companies_id ?? null,
            'from' => $params['from'] ?? null,
        ];

        if (! $message instanceof Message) {
            return $this->failWorkflow([
                'message' => 'Message not found',
                'entity' => null,
                'context' => $channelContext,
            ]);
        }

        $messageData = $message->message;
        $content = $messageData['content'] ?? [];
        $fromIa = (bool) ($messageData['from_ia'] ?? false);
        $fromOrchestrator = (bool) ($messageData['from_orchestrator'] ?? false);
        $fromExternalAi = $fromIa && $fromOrchestrator;

        $fromPhone = $params['from'] ?? $company->get(ConfigurationEnum::TWILIO_PHONE_NUMBER->value) ?? null;

        $messageContext = $channelContext + [
            'message_id' => $message->getId(),
            'message_uuid' => $message->uuid ?? null,
            'message_type' => $message->messageType?->verb,
            'message_from_ia' => $fromIa,
            'message_from_orchestrator' => $fromOrchestrator,
            'message_has_content' => ! empty($content),
        ];

        return $this->executeIntegration(
            entity: $channel,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($channel, $app, $integrationCompany, $additionalParams) use ($message, $content, $fromPhone, $fromExternalAi, $params, $messageContext) {
                $files = $message->getFiles();

                $messageContext['message_has_files'] = $files->isNotEmpty();
                $messageContext['message_files_count'] = $files->count();

                if (empty($content) && $files->isEmpty()) {
                    return $this->failWorkflow([
                        'message' => 'Message content and files are both empty',
                        'entity' => null,
                        'context' => $messageContext,
                    ]);
                }

                if (! $fromExternalAi) {
                    return $this->failWorkflow([
                        'message' => 'Message is not from an external AI (from_ia / from_orchestrator)',
                        'entity' => null,
                        'context' => $messageContext,
                    ]);
                }

                $channelEntity = $channel->entityData();
                $messageEntity = $message->entity();

                $messageContext['channel_entity_class'] = $channelEntity ? $channelEntity::class : null;
                $messageContext['message_entity_class'] = $messageEntity ? $messageEntity::class : null;

                if (! $messageEntity instanceof Lead && $channelEntity instanceof Lead) {
                    return $this->failWorkflow([
                        'message' => 'Channel entity is not a Lead',
                        'entity' => null,
                        'context' => $messageContext,
                    ]);
                }

                $lead = $messageEntity instanceof Lead ? $messageEntity : $channelEntity;

                $messageContext['lead_id'] = $lead?->getId();
                $messageContext['lead_uuid'] = $lead?->uuid ?? null;

                $channelType = match ($message->messageType->verb) {
                    ChannelCategoryEnum::WHATSAPP->value => LeadCommunicationChannelEnum::WHATSAPP->value,

                    ChannelCategoryEnum::EMAIL->value,
                    ChannelCategoryEnum::MAILGUN->value
                        => LeadCommunicationChannelEnum::EMAIL->value,

                    ChannelCategoryEnum::SMS->value => LeadCommunicationChannelEnum::SMS->value,
                    default => LeadCommunicationChannelEnum::SMS->value,
                };

                $messageContext['channel_type'] = $channelType;

                // Email delivery doesn't use a from-phone; only the phone-based channels require it.
                $phoneChannels = [
                    LeadCommunicationChannelEnum::SMS->value,
                    LeadCommunicationChannelEnum::WHATSAPP->value,
                ];
                if (in_array($channelType, $phoneChannels, true) && empty($fromPhone)) {
                    return $this->failWorkflow([
                        'message' => 'From phone number is required for ' . $channelType,
                        'entity' => null,
                        'context' => $messageContext,
                    ]);
                }

                $lastMessage = $channel->getLastMessage();
                if ($lastMessage && $lastMessage->isLocked() && strtolower((string) $lastMessage->messageType?->verb) !== 'note') {
                    $channel->deleteLastMessageLocked();
                }

                //we have a loop when you create a msg , its sent back via webhook, so we hide the msg that initiate everything
                if ($message->messageType->verb === ChannelCategoryEnum::WHATSAPP->value) {
                    $message->is_public = 0;
                    $message->save();
                }

                $message->addTag('engagement');

                $result = new SendMessageToLeadAction($lead)->execute(
                    $channelType,
                    $content,
                    $fromPhone,
                    $params['title'] ?? null,
                    false,
                    $files->isNotEmpty() ? $files : null
                );
                new StoreMessageSidAction($message)->execute($result);

                new MarkLeadMessagesAsRespondedAction($lead, $message)->execute();
                new NotifyLeadStakeholdersService($lead)->onAgentReply($message, isHuman: false);

                return [
                    'message' => 'Message sent to lead via ' . $channelType,
                    'result' => $result,
                    'context' => $messageContext,
                ];
            },
            company: $channel->company,
            additionalParams: $params,
        );
    }
}
