<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Workflows;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class HumanAgentChannelResponseActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Channel $channel, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        $message = $params['content'] ?? null;
        $user = $params['user'] ?? null; //@todo fix this get the user from the message

        $fromPhone = $params['from'] ?? null;

        return $this->executeIntegration(
            entity: $channel,
            app: $app,
            integration: IntegrationsEnum::TWILIO,
            integrationOperation: function ($channel, $app, $integrationCompany, $additionalParams) use ($message, $fromPhone, $params) {
                if (empty($message)) {
                    return $this->failWorkflow([
                        'message' => 'Message or user not found',
                        'entity' => null,
                    ]);
                }

                if (empty($fromPhone)) {
                    return $this->failWorkflow([
                        'message' => 'From phone number is required',
                        'entity' => null,
                    ]);
                }

                $lead = $channel->entityData();

                if (! $lead instanceof Lead) {
                    return $this->failWorkflow([
                        'message' => 'Channel entity is not a Lead',
                        'entity' => null,
                    ]);
                }

                return new SendMessageToLeadAction($lead)->execute(
                    LeadCommunicationChannelEnum::SMS->value,
                    $message,
                    $fromPhone,
                    $params['title'] ?? null,
                );
            },
            company: $channel->company,
        );
    }
}
