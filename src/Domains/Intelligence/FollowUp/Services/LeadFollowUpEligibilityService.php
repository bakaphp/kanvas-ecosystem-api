<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Services;

use Illuminate\Support\Carbon;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\FollowUp\DataTransferObject\FollowUpConfig;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpModeEnum;
use Kanvas\Intelligence\Services\LeadConfigurationService;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;

/**
 * Lead gates shared by the hourly fan-out and FollowUpLeadAction. The dispatcher runs them before
 * queueing so a lead that was already followed up (or is muted, manual, exhausted...) never reaches
 * the `lead_follow_ups` queue; the action re-runs them because forced runs skip the dispatcher and
 * the lead can change between dispatch and execution.
 */
final class LeadFollowUpEligibilityService
{
    /**
     * Null means queue. Leads due for exhaustion (max retries, handoff) are queued on purpose:
     * only the action marks them exhausted and applies the stage's exhausted action, after which
     * the `exhausted` gate keeps them out.
     */
    public function dispatchSkipReason(Lead $lead): ?string
    {
        $config = FollowUpConfig::fromStage($lead->stage);

        $reason = $this->skipReason($lead, $config);
        if ($reason !== null || $this->isDueForExhaustion($lead, $config)) {
            return $reason;
        }

        return $this->pausedReason($lead) ?? $this->tooSoonReason($lead, $config);
    }

    /**
     * Gates that run before exhaustion; none of them is bypassed by a forced run.
     */
    public function skipReason(Lead $lead, ?FollowUpConfig $config): ?string
    {
        if ($config === null || ! $config->enabled) {
            return 'follow_up_disabled';
        }

        // V1 ships time_based only; goal_based shape is reserved.
        if ($config->mode !== FollowUpModeEnum::TIME_BASED || $config->timeBased === null) {
            return 'unsupported_mode_v1';
        }

        if ($this->aiFollowUpDisabled($lead)) {
            return 'lead_follow_up_disabled';
        }

        // Lead-type defaults can veto v2, including manual/forced runs.
        // Check before retries so disabled types cannot advance stages either.
        $leadConfiguration = new LeadConfigurationService();
        if (! $leadConfiguration->allowsContinuedFollowUp($lead)) {
            return 'lead_type_follow_up_disabled';
        }

        if ($leadConfiguration->isCreatedBeforeFollowUpStartDate($lead)) {
            return 'lead_created_before_follow_up_start_date';
        }

        // Judged on `leads_status_id`, the column CRM pulls and the UI write — not `isOpen()`'s
        // `status` column, which nothing writes.
        if (! $lead->hasOpenLeadStatus()) {
            return 'lead_not_active';
        }

        if ($lead->isFollowUpExhausted()) {
            return 'exhausted';
        }

        return null;
    }

    public function isDueForExhaustion(Lead $lead, FollowUpConfig $config): bool
    {
        return $lead->getFollowUpStateCount() >= $config->maxRetries
            || $this->isHandedOff($lead);
    }

    public function isHandedOff(Lead $lead): bool
    {
        return (bool) $lead->get(IntelligenceConfigurationEnum::AGENT_HAND_OFF->value);
    }

    /**
     * Manual mode and IDLE/OFF are pausable — humans toggle them, the next tick resumes.
     */
    public function pausedReason(Lead $lead): ?string
    {
        if ((bool) $lead->get(LeadConfigurationEnum::AI_MODE_IS_MANUAL->value)) {
            return 'ai_mode_manual';
        }

        // IDLE/OFF silences the agent on every outbound path (reach-out, channel responders).
        return $lead->isAiMuted() ? 'ai_mode_off' : null;
    }

    public function tooSoonReason(Lead $lead, FollowUpConfig $config): ?string
    {
        return $this->silenceMinutes($lead) < $config->timeBased->intervalMinutes ? 'too_soon' : null;
    }

    /**
     * Minutes since the latest of stage entry, last follow-up or last message in either direction,
     * so a lead that was just followed up (or just talked to) waits the stage's full interval.
     */
    public function silenceMinutes(Lead $lead): int
    {
        return $lead->followUpSilenceMinutesSince($this->getLastMessageAt($lead));
    }

    /**
     * Absent `ai_follow_up` means the triggers never configured the lead, so it passes; once
     * present it must be explicitly on — OFF (0) and NO_FOLLOW_UP (3) both disable.
     */
    public function aiFollowUpDisabled(Lead $lead): bool
    {
        return filled($lead->get(IntelligenceModeEnum::AI_FOLLOW_UP->value))
            && ! $lead->isAiFollowUpEnabled();
    }

    /**
     * Not one of the engine's gates: there a reply only restarts the interval. For callers whose
     * sequence ends on any reply.
     */
    public function customerRepliedSinceLastTouch(Lead $lead): bool
    {
        $lastInboundAt = $this->getLastInboundAt($lead);
        $lastTouchAt = $lead->getFollowUpLastAt() ?? $lead->getFollowUpStageEnteredAt() ?? $lead->created_at;

        return $lastInboundAt !== null && $lastInboundAt->gt($lastTouchAt);
    }

    public function getLastInboundAt(Lead $lead): ?Carbon
    {
        return $this->lastPersonMessageAt($lead, inboundOnly: true);
    }

    public function getLastMessageAt(Lead $lead): ?Carbon
    {
        return $this->lastPersonMessageAt($lead, inboundOnly: false);
    }

    // Across ALL of the person's channels (lead-scoped): a session-scoped query missed messages
    // that landed on a different channel than the picked session.
    private function lastPersonMessageAt(Lead $lead, bool $inboundOnly): ?Carbon
    {
        if (! $lead->people_id) {
            return null;
        }

        $channelIds = Channel::query()
            ->where('entity_namespace', People::class)
            ->where('entity_id', $lead->people_id)
            ->where('is_deleted', 0)
            ->where('apps_id', $lead->apps_id)
            ->where('companies_id', $lead->companies_id)
            ->pluck('id');

        if ($channelIds->isEmpty()) {
            return null;
        }

        // No verb filter — `from_me = false` on a People-keyed channel is a strong-enough signal
        // that this is the customer's inbound, whatever channel it landed on.
        $message = Message::query()
            ->when($inboundOnly, fn ($query) => $query->where('message->from_me', false))
            ->where('is_deleted', 0)
            ->whereHas(
                'channels',
                fn ($q) => $q->whereIn('channels.id', $channelIds),
            )
            ->latest('created_at')
            ->first();

        return $message?->created_at ? Carbon::parse($message->created_at) : null;
    }
}
