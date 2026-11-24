<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Observers;

use InvalidArgumentException;
use Kanvas\ActionEngine\Engagements\Events\EngagementCompanyUpdateEvent;
use Kanvas\ActionEngine\Engagements\Events\EngagementCompletedEvent;
use Kanvas\ActionEngine\Engagements\Events\EngagementReferenceCompletedEvent;
use Kanvas\ActionEngine\Engagements\Events\EngagementStatusChangedEvent;
use Kanvas\ActionEngine\Engagements\Jobs\NotifyEngagementPipelineStageJob;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Engagements\Support\EngagementReferenceMap;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;

class EngagementObserver
{
    /**
     * entity_type is resolved to a class on read, so an unregistered value must never be stored.
     */
    public function saving(Engagement $engagement): void
    {
        $type = $engagement->entity_type;
        $id = $engagement->entity_id;

        if ($type === null && $id === null) {
            return;
        }

        if ($type === null || $type === '' || $id === null) {
            throw new InvalidArgumentException('An engagement reference needs both entity_type and entity_id.');
        }

        if (! EngagementReferenceMap::isRegistered($type)) {
            throw new InvalidArgumentException("'{$type}' is not a registered engagement reference type.");
        }
    }

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

        $this->dispatchLeadStatusChanged($engagement);
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

        $this->dispatchLeadStatusChanged($engagement);
    }

    /**
     * The event notifies the lead's stakeholders on the lead owner's channel; an engagement on a
     * quote or invoice has neither.
     */
    private function dispatchLeadStatusChanged(Engagement $engagement): void
    {
        if ($engagement->hasNonLeadReference()) {
            return;
        }

        EngagementStatusChangedEvent::dispatch($engagement);
    }

    /**
     * Runs before EngagementStatusChangedEvent on purpose: that event's constructor performs the
     * whole notification fan-out and reads companyAction->action->slug unguarded, so a throw there
     * would take this broadcast with it.
     */
    private function broadcastCompletion(Engagement $engagement): void
    {
        if ($engagement->hasNonLeadReference()) {
            $this->broadcastReferenceCompletion($engagement);

            return;
        }

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

    /**
     * A document is done when its customer accepted it (`submitted`) or Scribe closed it (`completed`,
     * an invoice paid).
     */
    private function broadcastReferenceCompletion(Engagement $engagement): void
    {
        $status = $engagement->message?->getMessage()['status'] ?? null;
        $reference = $engagement->reference;

        if (! in_array($status, [ActionStatusEnum::SUBMITTED->value, ActionStatusEnum::COMPLETED->value], true) || $reference === null) {
            return;
        }

        EngagementReferenceCompletedEvent::dispatch(
            entityType: (string) $engagement->entity_type,
            entityId: (int) $engagement->entity_id,
            entityUuid: $reference->getUuid(),
            engagementId: $engagement->getId(),
            action: (string) $engagement->slug,
            status: (string) $status,
            messageId: (int) $engagement->message_id,
            completedAt: ($engagement->updated_at ?? $engagement->created_at)->toIso8601String()
        );
    }
}
