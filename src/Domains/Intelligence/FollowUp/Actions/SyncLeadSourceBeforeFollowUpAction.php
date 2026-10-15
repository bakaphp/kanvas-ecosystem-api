<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\CrmLeadSourceSynchronizer;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Intelligence\FollowUp\Enums\LeadSourceSyncOutcomeEnum;
use Throwable;

/**
 * A CRM without webhooks never tells us a lead was closed or sold, so the follow-up engine asks
 * before it speaks. The marker throttles to one pull per lead per interval: the hourly cron and
 * any forced run in between share it.
 */
final class SyncLeadSourceBeforeFollowUpAction
{
    public const int DEFAULT_INTERVAL_MINUTES = 55;

    public function __construct(
        private readonly Lead $lead,
        private readonly CrmLeadSourceSynchronizer $synchronizer,
    ) {
    }

    public function execute(): LeadSourceSyncOutcomeEnum
    {
        if (! $this->synchronizer->canSync($this->lead)) {
            return LeadSourceSyncOutcomeEnum::NOT_APPLICABLE;
        }

        if ($this->syncedWithinInterval()) {
            return LeadSourceSyncOutcomeEnum::FRESH;
        }

        try {
            $this->synchronizer->sync($this->lead);
        } catch (Throwable $e) {
            report($e);

            return LeadSourceSyncOutcomeEnum::FAILED;
        }

        $this->lead->set(LeadConfigurationEnum::FOLLOW_UP_SOURCE_SYNCED_AT->value, Carbon::now()->toIso8601String());
        // The follow-up gates read this instance; a connector pull may have closed the lead through its own.
        $this->lead->refresh();

        return LeadSourceSyncOutcomeEnum::SYNCED;
    }

    private function syncedWithinInterval(): bool
    {
        $syncedAt = $this->lead->get(LeadConfigurationEnum::FOLLOW_UP_SOURCE_SYNCED_AT->value);
        if (! is_string($syncedAt) || $syncedAt === '') {
            return false;
        }

        try {
            $syncedAt = Carbon::parse($syncedAt);
        } catch (Throwable) {
            return false;
        }

        return $syncedAt->addMinutes($this->intervalMinutes())->isFuture();
    }

    private function intervalMinutes(): int
    {
        $configured = (int) $this->lead->company->get(IntelligenceConfigurationEnum::LEAD_FOLLOW_UP_SYNC_INTERVAL_MINUTES->value);

        return $configured > 0 ? $configured : self::DEFAULT_INTERVAL_MINUTES;
    }
}
