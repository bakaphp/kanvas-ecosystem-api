<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Elead\Entities\SalesActivities;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;

class UpdateEleadActivityFromEventAction
{
    public function __construct(
        protected Event $event,
        protected Lead $lead,
        protected array $params = []
    ) {
    }

    public function execute(): SalesActivities
    {
        $activityId = (string) $this->event->get(CustomFieldEnum::ACTIVITY_ID->value);

        if ($activityId === '') {
            throw new ValidationException('Event has no eLead activity to update');
        }

        $startsAt = $this->event->startsAt()
            ?? throw new ValidationException('Event has no start date to schedule in eLead');

        $activity = SalesActivities::fromResponse($this->event->app, $this->event->company, [
            'activityId' => $activityId,
            'opportunityId' => (string) $this->lead->get(CustomFieldEnum::OPPORTUNITY_ID->value),
        ]);

        $comments = (string) ($this->params['comments'] ?? $this->event->description ?? '');

        // The Activities API has no cancel; the activity stays open in eLead, so the
        // cancellation is written where the salesperson will read it.
        if ($this->event->isCancelled()) {
            $comments = trim('Cancelled in Kanvas on ' . Carbon::now()->toDayDateTimeString() . '. ' . $comments);
            $activity->lead()->addComment("Appointment \"{$this->event->name}\" was cancelled in Kanvas.");
        }

        $activity->update([
            'dueDate' => $startsAt->format(SalesActivities::DATE_FORMAT),
            'activityName' => $this->params['activity_name'] ?? $this->event->name,
            'comments' => $comments,
        ]);

        return $activity;
    }
}
