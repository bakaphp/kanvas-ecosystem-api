<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Workflows;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Enums\NotificationChannelEnum;
use Kanvas\Intelligence\Notifications\LeadNotification;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class SendNotificationActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Lead $lead, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($lead, $app) use ($params) {
                $userId = $params['user_id'] ?? null;
                $text = $params['text'] ?? '';

                if (empty($userId)) {
                    return [
                        'success' => false,
                        'message' => 'User ID is required',
                    ];
                }

                if (empty($text)) {
                    return [
                        'success' => false,
                        'message' => 'Notification text is required',
                    ];
                }

                $user = Users::getById($userId);
                $company = $lead->company;

                $enabledChannels = $this->getEnabledChannels($company);

                if (empty($enabledChannels)) {
                    return [
                        'success' => false,
                        'message' => 'No notification channels enabled for this company',
                    ];
                }

                $notification = new LeadNotification(
                    lead: $lead,
                    message: $text,
                    enabledChannels: $enabledChannels,
                    app: $app,
                    company: $company
                );

                $user->notify($notification);

                return [
                    'success' => true,
                    'message' => 'Notification sent successfully',
                    'channels' => $enabledChannels,
                    'user_id' => $userId,
                    'lead_id' => $lead->getId(),
                ];
            }
        );
    }

    private function getEnabledChannels($company): array
    {
        $configuredChannels = $company->get(ConfigurationEnum::NOTIFICATION_CHANNELS->value);

        if (empty($configuredChannels)) {
            return [];
        }

        if (\is_string($configuredChannels)) {
            $configuredChannels = json_decode($configuredChannels, true) ?? [];
        }

        return array_filter(
            $configuredChannels,
            fn ($channel) => NotificationChannelEnum::isSupported($channel)
        );
    }
}
