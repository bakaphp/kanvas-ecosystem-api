<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\FollowUp\Services\LeadFollowUpActivityService;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Ledger\Models\Event;

/**
 * Daily rollup of `lead.follow_up.*` + `lead.pipeline.completed` ledger
 * events for one (app, company), emitting a single `follow_up.daily.summary`.
 *
 * Not idempotent: a re-run for the same date emits a second summary event.
 * Consumers pick latest by `occurred_at`.
 */
final class BuildLeadFollowUpDailySummaryAction
{
    public function __construct(
        protected readonly Apps $app,
        protected readonly Companies $company,
        protected readonly Carbon $forDate,
    ) {
    }

    public function execute(): Event
    {
        $tz = $this->company->timezone ?? 'UTC';
        $day = $this->forDate->copy()->setTimezone($tz);

        $summary = new LeadFollowUpActivityService()->summarize(
            $this->app,
            $this->company,
            $day->copy()->startOfDay()->utc(),
            $day->copy()->endOfDay()->utc(),
        );
        $summary['for_date'] = $day->toDateString();
        $summary['app_id'] = $this->app->getId();
        $summary['company_id'] = $this->company->getId();

        return new AppendEventAction(
            new EventData(
                app: $this->app,
                company: $this->company,
                sourceDomain: 'follow_up',
                eventType: 'follow_up.daily.summary',
                status: EventStatusEnum::INFO,
                sourceEntityType: null,
                sourceEntityId: null,
                payload: $summary,
            ),
        )->execute();
    }
}
