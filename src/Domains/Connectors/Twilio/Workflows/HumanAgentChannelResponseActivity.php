<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Workflows;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Actions\StoreMessageSidAction;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Enums\LeadGroupStatusEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\NotifyLeadStakeholdersService;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Enums\ChannelCategoryEnum;
use Kanvas\Social\Messages\Actions\MarkLeadMessagesAsRespondedAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\KanvasActivity;

/**
 * @todo move to a SA namespace, this is not for Twilio anymore
 */
#[WorkflowAction(
    name: 'Human Agent Channel Response',
    description: 'Delivers a HUMAN colleague\'s reply out to the customer\'s channel — the human-in-the-loop '
        . 'counterpart to the agent responders. Nothing here is written by an agent.',
    integration: IntegrationsEnum::INTERNAL,
    params: [
        'message' => 'Supplied by the trigger — the message that arrived.',
        'user' => 'The human replying.',
        'title' => 'Optional title for the outbound notification.',
        'from' => 'Optional sender identity.',
    ],
)]
class HumanAgentChannelResponseActivity extends KanvasActivity
{
    private const string SMS_OPT_OUT_NOTICE = 'Reply STOP to opt out.';

    public $tries = 3;

    public function execute(Channel $channel, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);
        $message = $params['message'] ?? null;

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
        $fromHumanAgent = $messageData['from_human'] ?? false;
        $user = $params['user'] ?? null; //@todo fix this get the user from the message

        $fromPhone = $params['from'] ?? null;

        $messageContext = $channelContext + [
            'message_id' => $message->getId(),
            'message_uuid' => $message->uuid ?? null,
            'message_type' => $message->messageType?->verb,
            'message_from_human' => (bool) $fromHumanAgent,
            'message_has_content' => ! empty($content),
        ];

        return $this->executeIntegration(
            entity: $channel,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($channel, $app, $integrationCompany, $additionalParams) use ($message, $content, $fromPhone, $fromHumanAgent, $params, $messageContext) {
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

                if (empty($fromHumanAgent)) {
                    return $this->failWorkflow([
                        'message' => 'Message is not from human agent',
                        'entity' => null,
                        'context' => $messageContext,
                    ]);
                }

                if (empty($fromPhone)) {
                    return $this->failWorkflow([
                        'message' => 'From phone number is required',
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

                $lastMessage = $channel->getLastMessage();
                if ($lastMessage && $lastMessage->isLocked() && strtolower((string) $lastMessage->messageType?->verb) !== 'note') {
                    $channel->deleteLastMessageLocked();
                }

                $lead->fireWorkflow(
                    WorkflowEnum::TRIGGER_AI->value,
                    true,
                    [
                        'app' => $lead->app,
                        'trigger_type' => TriggersEnum::HUMAN_TAKEOVER->value,
                    ]
                );
                $messageEntity->set(ConfigurationEnum::CONTACTED->value, LeadGroupStatusEnum::CONTACTED->value);

                $lead->setContactStatus(LeadGroupStatusEnum::CONTACTED);

                $channelType = match ($message->messageType->verb) {
                    ChannelCategoryEnum::WHATSAPP->value => LeadCommunicationChannelEnum::WHATSAPP->value,

                    ChannelCategoryEnum::EMAIL->value,
                    ChannelCategoryEnum::MAILGUN->value
                        => LeadCommunicationChannelEnum::EMAIL->value,

                    ChannelCategoryEnum::SMS->value => LeadCommunicationChannelEnum::SMS->value,
                    default => LeadCommunicationChannelEnum::SMS->value,
                };

                $messageContext['channel_type'] = $channelType;

                //we have a loop when you create a msg , its sent back via webhook, so we hide the msg that initiate everything
                if ($message->messageType->verb === ChannelCategoryEnum::WHATSAPP->value) {
                    $message->is_public = 0;
                    $message->save();
                }

                $message->addTag('engagement');

                if (
                    $channelType === LeadCommunicationChannelEnum::SMS->value
                    && $this->isFirstChannelMessage($channel, $message)
                ) {
                    $body = is_string($content) ? $content : '';
                    if (! $this->alreadyHasOptOutNotice($body)) {
                        $content = trim($body) . "\n\n" . self::SMS_OPT_OUT_NOTICE;
                    }
                }

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
                new NotifyLeadStakeholdersService($lead)->onAgentReply($message, isHuman: true);

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

    private function isFirstChannelMessage(Channel $channel, Message $message): bool
    {
        return $channel->messages()
            ->where('messages.id', '!=', $message->getId())
            ->where('messages.is_deleted', 0)
            ->doesntExist();
    }

    private function alreadyHasOptOutNotice(string $body): bool
    {
        foreach (['reply stop', 'opt out', 'opt-out'] as $needle) {
            if (stripos($body, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
