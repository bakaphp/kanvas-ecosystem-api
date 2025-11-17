<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Workflows;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

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

                return new SendMessageToLeadAction($lead)->execute(
                    LeadCommunicationChannelEnum::SMS->value,
                    $content,
                    $fromPhone,
                    $params['title'] ?? null,
                );
            },
            company: $channel->company,
        );
    }
}
