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

class AddOutBoundPhoneCallActivityToLeadAction
{
    public function __construct(
        protected Lead $lead
    ) {
    }

    public function execute(): array
    {
        if (empty($this->lead->get(CustomFieldEnum::OPPORTUNITY_ID->value))) {
            throw new InvalidArgumentException('Lead does not have an opportunity id set');
        }

        $leadActivities = SalesActivities::getOpenActivitiesByOpportunityId(
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
    }
}
