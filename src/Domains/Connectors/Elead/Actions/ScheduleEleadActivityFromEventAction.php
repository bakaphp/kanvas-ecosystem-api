<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Actions;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Elead\Entities\SalesActivities;
use Kanvas\Connectors\Elead\Enums\ConfigurationEnum;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
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
        $app = $this->event->app;
        $company = $this->event->company;

        $opportunityId = $this->lead->get(CustomFieldEnum::OPPORTUNITY_ID->value);
        if (empty($opportunityId)) {
            throw new ValidationException('Lead does not have an eLead opportunity ID');
        }

        $activityTypeName = $this->params['activity_type_name']
            ?? $this->event->eventType?->name
            ?? 'Phone Call';
        $activityTypes = SalesActivities::getActivityTypes($app, $company);
        $activityTypeId = null;
        foreach ($activityTypes['items'] as $type) {
            if (Str::contains($type['name'], $activityTypeName)) {
                $activityTypeId = $type['id'];

                break;
            }
        }

        if ($activityTypeId === null) {
            throw new ValidationException("Activity type '{$activityTypeName}' not found in eLead");
        }

        $dueDate = $this->resolveDueDate();

        $assignToEmployeeId = null;
        if ($this->lead->owner) {
            $assignToEmployeeId = $this->lead->owner->get(
                ConfigurationEnum::getUserKey($company, $this->lead->owner)
            );
        }

        $data = [
            'opportunityId' => $opportunityId,
            'dueDate' => $dueDate,
            'activityName' => $this->params['activity_name'] ?? $this->event->name,
            'activityType' => $activityTypeId,
            'comments' => $this->params['comments'] ?? $this->event->description ?? '',
        ];

        if ($assignToEmployeeId !== null && $assignToEmployeeId !== '') {
            $data['assignToEmployeeId'] = $assignToEmployeeId;
        }

        return SalesActivities::create($app, $company, $data);
    }

    protected function resolveDueDate(): string
    {
        $timezone = $this->event->company->timezone ?: 'UTC';

        /** @var \Kanvas\Event\Events\Models\EventVersion|null $version */
        $version = $this->event->versions()->latest()->first();

        if ($version !== null) {
            $date = $version->dates()->first();
            if ($date !== null) {
                return Carbon::parse(
                    (string) $date->event_date . ' ' . ((string) ($date->start_time ?? '00:00'))
                )->format('Y-m-d\TH:i:s.v\Z');
            }

            if ($version->start_at !== null) {
                return $version->start_at
                    ->setTimezone($timezone)
                    ->format('Y-m-d\TH:i:s.v\Z');
            }
        }

        return Carbon::now($timezone)
            ->addHour()
            ->format('Y-m-d\TH:i:s.v\Z');
    }
}
