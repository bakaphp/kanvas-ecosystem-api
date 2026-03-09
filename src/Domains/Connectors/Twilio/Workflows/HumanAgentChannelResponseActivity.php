<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Workflows;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Support\UnrespondedLeadAgentMessageCache;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Enums\ChannelCategoryEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\KanvasActivity;

/**
 * @todo move to a SA namespace, this is not for Twilio anymore
 */
class HumanAgentChannelResponseActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Channel $channel, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);
        $message = $params['message'] ?? null;

        if (! $message instanceof Message) {
            return $this->failWorkflow([
                'message' => 'Message not found',
                'entity' => null,
            ]);
        }

        $messageData = $message->message;
        $content = $messageData['content'] ?? [];
        $fromHumanAgent = $messageData['from_human'] ?? false;
        $user = $params['user'] ?? null; //@todo fix this get the user from the message

        $fromPhone = $params['from'] ?? null;

        return $this->executeIntegration(
            entity: $channel,
            app: $app,
            integration: IntegrationsEnum::TWILIO,
            integrationOperation: function ($channel, $app, $integrationCompany, $additionalParams) use ($message, $content, $fromPhone, $fromHumanAgent, $params) {
                if (empty($content)) {
                    return $this->failWorkflow([
                        'message' => 'Message or user not found',
                        'entity' => null,
                    ]);
                }

                if (empty($fromHumanAgent)) {
                    return $this->failWorkflow([
                        'message' => 'Message is not from human agent',
                        'entity' => null,
                    ]);
                }

                if (empty($fromPhone)) {
                    return $this->failWorkflow([
                        'message' => 'From phone number is required',
                        'entity' => null,
                    ]);
                }

                $channelEntity = $channel->entityData();
                $messageEntity = $message->entity();

                if (! $messageEntity instanceof Lead && $channelEntity instanceof Lead) {
                    return $this->failWorkflow([
                        'message' => 'Channel entity is not a Lead',
                        'entity' => null,
                    ]);
                }

                $lead = $messageEntity instanceof Lead ? $messageEntity : $channelEntity;

                UnrespondedLeadAgentMessageCache::clear($lead, $channel);

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

                $channelType = match ($message->messageType->verb) {
                    ChannelCategoryEnum::WHATSAPP->value => LeadCommunicationChannelEnum::WHATSAPP->value,

                    ChannelCategoryEnum::EMAIL->value,
                    ChannelCategoryEnum::MAILGUN->value
                        => LeadCommunicationChannelEnum::EMAIL->value,

                    ChannelCategoryEnum::SMS->value => LeadCommunicationChannelEnum::SMS->value,
                    default => LeadCommunicationChannelEnum::SMS->value,
                };

                //we have a loop when you create a msg , its sent back via webhook, so we hide the msg that initiate everything
                if ($message->messageType->verb === ChannelCategoryEnum::WHATSAPP->value) {
                    $message->is_public = 0;
                    $message->save();
                }

                $message->addTag('engagement');

                return new SendMessageToLeadAction($lead)->execute(
                    $channelType,
                    $content,
                    $fromPhone,
                    $params['title'] ?? null,
                    false
                );
            },
            company: $channel->company,
        );
    }
}
