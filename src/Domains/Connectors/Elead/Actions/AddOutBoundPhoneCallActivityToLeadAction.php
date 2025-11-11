<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Actions;

use Baka\Support\Str;
use DateTime;
use DateTimeZone;
use InvalidArgumentException;
use Kanvas\Connectors\Elead\Entities\SalesActivities;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Repositories\UsersRepository;

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

        $activityType = SalesActivities::getActivityTypes(
            $this->lead->app,
            $this->lead->company
        );

        $phoneCallActivityTypeId = null;
        foreach ($activityType['items'] as $type) {
            if (Str::contains($type['name'], 'Phone Call')) {
                //we have phone call activity type
                $phoneCallActivityTypeId = $type['id'];
            }
        }
        //create activity

        $activity = SalesActivities::createComplete(
            $this->lead->app,
            $this->lead->company,
            [
                'opportunityId' => $this->lead->get(CustomFieldEnum::OPPORTUNITY_ID->value),
                'activityName' => $name,
                'activityType' => $phoneCallActivityTypeId,
                'comments' => $comment,
                'completedDate' => (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
                'message' => [],
            ]
        );

        if ($this->lead->company->get('ai_stop_the_clock_notifications_enabled')) {
            $this->notifyManagers();
        }

        return $activity->activityId;
        /* $leadActivities = SalesActivities::getOpenActivitiesByOpportunityId(
            $this->lead->app,
            $this->lead->company,
            $this->lead->get(CustomFieldEnum::OPPORTUNITY_ID->value)
        );

        if (empty($leadActivities['items'])) {
            throw new InvalidArgumentException('No open activities found for this lead');
        }

        $activityId = null;
        foreach ($leadActivities['items'] as $activity) {
            if (Str::contains($activity['activityType'], 'Phone Call')) {
                $activityId = $activity['id'];

                break;
            }
        }

        if ($activityId === null) {
            throw new InvalidArgumentException('No open phone call activities found for this lead');
        }

        $activity = SalesActivities::getById($this->lead->app, $this->lead->company, $activityId);

        $currentDateTime = new DateTime('now', new DateTimeZone('UTC'));
        $currentFormattedDate = $currentDateTime->format('Y-m-d\TH:i:s.v\Z');
        $outboundCallData = [
            'callIdentifier' => '1',
            'startDateTime' => $currentFormattedDate,
            'durationSeconds' => 1,
            'agentId' => '1',
            'numberDialed' => '5555555555',
            'messageUrl' => 'https://salesassist.io/sally-engagement',
        ];

        return SalesActivities::addOutboundCallById($this->lead->app, $this->lead->company, $activityId, $outboundCallData);
 */
    }

    protected function notifyManagers(): void
    {
        $notification = new Blank(
            templateName: 'agent-manager-notification',
            data: [
                //'message' => $message,
                'company' => $this->lead->company,
                'app' => $this->lead->app,
                'user' => $this->lead->user,
                'content' => 'Sally just stopped the clock for lead ' . $this->lead->people->name,
                'title' => 'Sally Stopped the Clock',
                'message' => $this->message
            ],
            via: ['sms', 'push', 'expo'],
            entity: $this->lead
        );

        $notification->setSubject('Sally stop the clock for lead ' . $this->lead->people->name);
        $notification->setPushTemplateName('agent_manager_push_notification');
        $notification->setSmsTemplateName('agent_manager_sms_notification');

        //managers
        $managers = UsersRepository::getCompanyAppUserByRole(
            $this->lead->company,
            $this->lead->app,
            'BDCManager'
        )->get();

        foreach ($managers as $manager) {
            $manager->notify(
                $notification
            );
        }
    }
}
