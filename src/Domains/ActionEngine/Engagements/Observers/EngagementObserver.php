<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Observers;

use Kanvas\ActionEngine\Engagements\Events\EngagementCompanyUpdateEvent;
use Kanvas\ActionEngine\Engagements\Events\EngagementCompletedEvent;
use Kanvas\ActionEngine\Engagements\Events\EngagementStatusChangedEvent;
use Kanvas\ActionEngine\Engagements\Jobs\NotifyEngagementPipelineStageJob;
use Kanvas\ActionEngine\Engagements\Models\Engagement;

class EngagementObserver
{
    public function updated(Engagement $engagement): void
    {
        if (! $engagement->wasChanged('message_id') && ! $engagement->wasChanged('pipelines_stages_id')) {
            return;
        }

        if ($engagement->wasChanged('pipelines_stages_id')) {
            NotifyEngagementPipelineStageJob::dispatch($engagement);
        }

        $message = $engagement->message;
        if (! $message) {
            return;
        }

        $this->broadcastCompletion($engagement);

        EngagementStatusChangedEvent::dispatch($engagement);
        EngagementCompanyUpdateEvent::dispatch($engagement);
    }

    public function created(Engagement $engagement): void
    {
        NotifyEngagementPipelineStageJob::dispatch($engagement);

        $message = $engagement->message;
        if (! $message) {
            return;
        }

        $this->broadcastCompletion($engagement);

        EngagementStatusChangedEvent::dispatch($engagement);
    }

    /**
     * Runs before EngagementStatusChangedEvent on purpose: that event's constructor performs the
     * whole notification fan-out and reads companyAction->action->slug unguarded, so a throw there
     * would take this broadcast with it.
     */
    private function broadcastCompletion(Engagement $engagement): void
    {
        $lead = $engagement->lead;

        if (! $lead || ! $engagement->hasSubmittedMessage()) {
            return;
        }

        EngagementCompletedEvent::dispatch(
            leadId: $lead->getId(),
            leadUuid: (string) $lead->uuid,
            engagementId: $engagement->getId(),
            action: (string) $engagement->slug,
            companyActionId: (int) $engagement->companies_actions_id,
            messageId: (int) $engagement->message_id,
            completedAt: ($engagement->updated_at ?? $engagement->created_at)->toIso8601String()
        );
    }
}
