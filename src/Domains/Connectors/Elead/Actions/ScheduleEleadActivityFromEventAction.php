<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Actions;

use Kanvas\Connectors\Elead\Entities\SalesActivities;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\Elead\Services\LeadUserService;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;

class ScheduleEleadActivityFromEventAction
{
    public function __construct(
        protected Event $event,
        protected Lead $lead,
        protected array $params = []
    ) {
    }

    public function execute(): SalesActivities
    {
        // Workflow retries and the `updated` rule must not schedule the same event twice.
        if (! empty($this->event->get(CustomFieldEnum::ACTIVITY_ID->value))) {
            return new UpdateEleadActivityFromEventAction($this->event, $this->lead, $this->params)->execute();
        }

        $app = $this->event->app;
        $company = $this->event->company;

        $opportunityId = $this->lead->get(CustomFieldEnum::OPPORTUNITY_ID->value);
        if (empty($opportunityId)) {
            throw new ValidationException('Lead does not have an eLead opportunity ID');
        }

        $activityTypeName = $this->params['activity_type_name']
            ?? $this->event->eventType?->name
            ?? 'Phone Call';
        $activityTypeId = SalesActivities::findActivityTypeId($app, $company, $activityTypeName);

        if ($activityTypeId === null) {
            throw new ValidationException("Activity type '{$activityTypeName}' not found in eLead");
        }

        $startsAt = $this->event->startsAt()
            ?? throw new ValidationException('Event has no start date to schedule in eLead');

        $salesUser = LeadUserService::resolve($this->lead);
        $assignToEmployeeId = $salesUser?->get(CustomFieldEnum::getUserKey($company));

        $data = [
            'opportunityId' => $opportunityId,
            'dueDate' => $startsAt->format(SalesActivities::DATE_FORMAT),
            'activityName' => $this->params['activity_name'] ?? $this->event->name,
            'activityType' => $activityTypeId,
            'comments' => $this->params['comments'] ?? $this->event->description ?? '',
        ];

        if ($assignToEmployeeId !== null && $assignToEmployeeId !== '') {
            $data['assignToEmployeeId'] = $assignToEmployeeId;
        }

        $activity = SalesActivities::create($app, $company, $data);

        if ($activity->activityId !== null) {
            $this->event->set(CustomFieldEnum::ACTIVITY_ID->value, $activity->activityId);
        }

        return $activity;
    }
}
