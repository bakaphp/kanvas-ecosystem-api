<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Actions;

use DateTime;
use DateTimeZone;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\Connectors\Elead\Entities\SalesActivities;

class AddSalesActivityAction
{
    public function __construct(
        protected Engagement $engagement
    ) {
    }

    public function execute(string $actionName, string $comment): SalesActivities
    {
        $syncLead = new SyncLeadAction($this->engagement->lead);
        $eLead = $syncLead->execute();

        $amNY = new DateTime(date('Y-m-d H:i:s', strtotime('+1 hour')), new DateTimeZone('UTC'));

        $eLeadActivity = [
            'opportunityId' => $eLead->id,
            'dueDate' => $amNY->format('Y-m-d\TH:i:s.') . substr($amNY->format('u'), 0, 3) . 'Z',
            'activityName' => $actionName,
            'activityType' => 2, // Phone Call
            'comments' => $comment,
        ];

        //$eLead->addComment();

        return SalesActivities::create(
            $this->engagement->app,
            $this->engagement->companies,
            $eLeadActivity
        );
    }
}
