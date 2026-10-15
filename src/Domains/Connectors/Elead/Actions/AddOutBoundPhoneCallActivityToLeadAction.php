<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Actions;

use DateTime;
use DateTimeZone;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Kanvas\Companies\Services\CompanyManagerService;
use Kanvas\Connectors\Elead\Entities\SalesActivities;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Tools\CompanyWorkHoursTool;
use Kanvas\Notifications\Channels\OneSignalNotificationChannel;
use Kanvas\Notifications\Channels\TwilioSmsChannel;
use Kanvas\Notifications\Templates\EngagementNotification;
use Kanvas\Social\Messages\Models\Message;
use NotificationChannels\Expo\ExpoChannel;

class AddOutBoundPhoneCallActivityToLeadAction
{
    public function __construct(
        protected Lead $lead,
        protected Message $message
    ) {
    }

    public function execute(string $name, string $comment): ?string
    {
        if (empty($this->lead->get(CustomFieldEnum::OPPORTUNITY_ID->value))) {
            throw new InvalidArgumentException('Lead does not have an opportunity id set');
        }

        $phoneCallActivityTypeId = SalesActivities::findActivityTypeId($this->lead->app, $this->lead->company, 'Phone Call');

        $activity = SalesActivities::createComplete(
            $this->lead->app,
            $this->lead->company,
            [
                'opportunityId' => $this->lead->get(CustomFieldEnum::OPPORTUNITY_ID->value),
                'activityName' => $name,
                'activityType' => $phoneCallActivityTypeId,
                'comments' => $comment,
                'completedDate' => new DateTime('now', new DateTimeZone('UTC'))->format(SalesActivities::DATE_FORMAT),
                'message' => [],
            ]
        );

        if ($this->lead->company->get('ai_stop_the_clock_notifications_enabled')) {
            $this->notifyManagers();
        }

        return $activity->activityId;
    }

    protected function notifyManagers(): void
    {
        $hoursTool = new CompanyWorkHoursTool($this->lead)->execute();
        if ($hoursTool['status'] !== 'work_hours') {
            return;
        }

        $notification = new EngagementNotification(
            templateName: 'agent-manager-notification',
            data: [
                //'message' => $message,
                'company' => $this->lead->company,
                'app' => $this->lead->app,
                'user' => $this->lead->user,
                'content' => 'Sally just stopped the clock for lead ' . $this->lead->people->name,
                'title' => 'Sally Stopped the Clock',
                'message' => $this->message,
                'lead_name' => $this->lead->people->name,
                'lead_id' => $this->lead->getId(),
                'people_id' => $this->lead->people->getId(),
                'branch_id' => $this->lead->companies_branches_id,
            ],
            via: ['sms', 'push', 'expo', 'mail', 'database'],
            entity: $this->lead
        );

        $notification->setSubject('Sally stop the clock for lead ' . $this->lead->people->name);
        $notification->setPushTemplateName('agent_manager_push_notification');
        $notification->setSmsTemplateName('agent_manager_sms_notification');
        $notification->setDatabaseTemplateName('agent_manager_sms_notification');

        $this->configureNotificationChannels($notification);

        $managers = new CompanyManagerService($this->lead->company, $this->lead->app)
            ->getManagersByRole('BDCManager');

        Notification::send($managers, $notification);
    }

    protected function configureNotificationChannels(EngagementNotification $notification): void
    {
        $onlySms = (bool) $this->lead->company->get('ai_stop_clock_notification_only_sms');
        $onlyMail = (bool) $this->lead->company->get('ai_stop_clock_notification_only_mail');
        $onlyPush = (bool) $this->lead->company->get('ai_stop_clock_notification_only_push');

        if ($onlySms) {
            $notification->channels = [TwilioSmsChannel::class];
        } elseif ($onlyMail) {
            $notification->channels = ['mail'];
        } elseif ($onlyPush) {
            $notification->channels = [
                OneSignalNotificationChannel::class,
                ExpoChannel::class,
            ];
        }
    }
}
