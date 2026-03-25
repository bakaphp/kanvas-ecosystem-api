<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Workflows;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class SaveLeadPreferredChannelActivity extends KanvasActivity
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

        return $this->executeIntegration(
            entity: $channel,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function () use ($message, $channel) {
                $messageEntity = $message->entity();

                if (! $messageEntity instanceof Lead) {
                    return $this->failWorkflow([
                        'message' => 'Message entity is not a Lead',
                        'entity' => null,
                    ]);
                }

                $messageEntity->set(ConfigurationEnum::GUILD_PREFERED_CHANNEL_UUID->value, $channel->uuid);

                return [
                    'success' => true,
                    'message' => 'Preferred channel saved successfully',
                    'lead_id' => $messageEntity->getId(),
                    'channel_uuid' => $channel->uuid,
                ];
            },
            company: $channel->company,
        );
    }
}
