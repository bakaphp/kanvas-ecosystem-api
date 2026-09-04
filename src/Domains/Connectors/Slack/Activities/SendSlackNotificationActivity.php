<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Activities;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Slack\Enums\NotificationConfigurationEnum;
use Kanvas\Connectors\Slack\Services\SlackNotificationService;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

/**
 * Wire this to any rule — the entity is only used to resolve which company/region the Slack
 * notification credentials belong to, the same way `PushMessageToWordPressActivity` uses the
 * message purely to find its company.
 */
#[WorkflowAction(
    name: 'Send Slack Notification',
    description: 'Posts a text notification to Slack using the company\'s configured Incoming Webhook '
        . 'URL or Bot User OAuth Token. Use it to alert a channel from any workflow rule — new order, '
        . 'failed sync, escalated lead, etc.',
    integration: IntegrationsEnum::SLACK,
    requiresConfig: [
        NotificationConfigurationEnum::WEBHOOK_URL,
        NotificationConfigurationEnum::BOT_TOKEN,
    ],
    requiredParams: ['text'],
    params: [
        'text' => 'The message body to post to Slack. Required.',
        'channel' => 'Channel id (C0123456789) or name (#alerts). Only consulted when sending via bot '
            . 'token — a webhook is already bound to a single channel on Slack\'s side. Falls back to '
            . 'the connector\'s configured default_channel.',
    ],
)]
class SendSlackNotificationActivity extends KanvasActivity
{
    public function execute(Model $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        $text = trim((string) ($params['text'] ?? ''));

        if ($text === '') {
            return $this->failWorkflow([
                'message' => 'Missing required param "text"',
                'entity' => [get_class($entity), $entity->getId()],
            ]);
        }

        $company = $entity->company;
        $channel = isset($params['channel']) && trim((string) $params['channel']) !== ''
            ? trim((string) $params['channel'])
            : null;

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::SLACK,
            additionalParams: $params,
            integrationOperation: function (Model $entity, Apps $app, mixed $integrationCompany, array $additionalParams) use ($text, $channel, $company): array {
                $service = new SlackNotificationService($app, $company);

                if (! $service->isConfigured()) {
                    return $this->failWorkflow([
                        'message' => 'Slack notifications are not configured for this company',
                        'entity' => [get_class($entity), $entity->getId()],
                    ]);
                }

                return $service->send($text, $channel);
            },
            company: $company,
        );
    }
}
