<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Kanvas\Companies\Enums\ConfigurationEnum as CompanyConfigurationEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\FollowUp\Actions\EndLeadFollowUpPlanAction;
use Kanvas\Intelligence\FollowUp\DataTransferObject\FollowUpConfig;
use Kanvas\Intelligence\FollowUp\DataTransferObject\ManualFollowUpPlan;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpModeEnum;
use Kanvas\Intelligence\Services\LeadConfigurationService;
use Kanvas\Intelligence\Tools\CompanyWorkHoursTool;
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
        $reason = $this->concludeRepliedPlan($lead);
        if ($reason !== null) {
            return $reason;
        }

        $config = $this->configFor($lead);

        $reason = $this->skipReason($lead, $config);
        if ($reason !== null || $this->isDueForExhaustion($lead, $config)) {
            return $reason;
        }

        return $this->pausedReason($lead)
            ?? $this->tooSoonReason($lead, $config)
            ?? $this->outsideWorkHoursReason($lead, $config);
    }

    /**
     * A person's plan on the lead replaces the stage config while it runs; a lead with no stage can
     * still carry one.
     */
    public function configFor(Lead $lead): ?FollowUpConfig
    {
        $plan = $lead->getFollowUpPlan();
        if ($plan !== null) {
            return FollowUpConfig::fromPlan($plan);
        }

        return $lead->stage !== null ? FollowUpConfig::fromStage($lead->stage) : null;
    }

    /**
     * Ends a stop-on-reply plan on the first inbound since it was set, and names it. Judged on the
     * time the plan was set, not its start: a lead who answers before a delayed first touch has
     * answered.
     */
    public function concludeRepliedPlan(Lead $lead, ?Carbon $inboundAt = null): ?string
    {
        $plan = $lead->getFollowUpPlan();
        if ($plan === null || ! $plan->stopOnReply) {
            return null;
        }

        $inboundAt ??= $this->getLastInboundAt($lead);
        if ($inboundAt === null || ! $inboundAt->greaterThan($plan->setAt)) {
            return null;
        }

        new EndLeadFollowUpPlanAction($lead, ManualFollowUpPlan::REASON_REPLIED)->execute();

        return 'plan_replied';
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

        // Lead-type defaults and the company's start date are admin config for the automatic
        // follow-up; a person's plan on one lead, or a person turning follow-up on for it by hand,
        // is an explicit instruction and is not vetoed by them. Checked before retries so disabled
        // types cannot advance stages either.
        $leadConfiguration = new LeadConfigurationService();
        if ($config->plan === null && ! $leadConfiguration->hasManualFollowUp($lead)) {
            if (! $leadConfiguration->allowsContinuedFollowUp($lead)) {
                return 'lead_type_follow_up_disabled';
            }

            if ($leadConfiguration->isCreatedBeforeFollowUpStartDate($lead)) {
                return 'lead_created_before_follow_up_start_date';
            }
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

    /**
     * A plan's first touch goes out at its start, whatever was said before; later ones wait the
     * interval after the previous touch only, since a reply ends the plan instead of restarting it.
     */
    public function tooSoonReason(Lead $lead, FollowUpConfig $config): ?string
    {
        $plan = $config->plan;
        if ($plan === null) {
            return $this->silenceMinutes($lead) < $config->timeBased->intervalMinutes ? 'too_soon' : null;
        }

        if ($plan->startAt->isFuture()) {
            return 'plan_not_started';
        }

        return $plan->nextDueAt($lead->getFollowUpLastAt())->isFuture() ? 'too_soon' : null;
    }

    /**
     * Enforced for plans only, and only when the company has work hours set: without them the
     * work-hours tool answers "after hours" around the clock. Stage follow-ups carry the same flag
     * but never honoured it, and switching that on here would silence every tenant's night sends.
     */
    public function outsideWorkHoursReason(Lead $lead, FollowUpConfig $config): ?string
    {
        if ($config->plan === null || ! $config->respectWorkHours) {
            return null;
        }

        $company = $lead->company;
        if (empty($company->get(CompanyConfigurationEnum::WORKING_HOURS->value))) {
            return null;
        }

        $hours = new CompanyWorkHoursTool($company)->execute();

        return $hours['status'] === 'work_hours' ? null : 'outside_work_hours';
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

    /**
     * The same "last word from this person" for an entity that is not a lead (an invoice being
     * dunned): the person's channels inside the given tenant.
     */
    public function getLastInboundAtForPerson(People $people, int $appsId, int $companiesId): ?Carbon
    {
        return $this->lastMessageAtForPerson(
            $people->getId(),
            $appsId,
            $companiesId,
            inboundOnly: true
        );
    }

    private function lastPersonMessageAt(Lead $lead, bool $inboundOnly): ?Carbon
    {
        if (! $lead->people_id) {
            return null;
        }

        return $this->lastMessageAtForPerson(
            (int) $lead->people_id,
            (int) $lead->apps_id,
            (int) $lead->companies_id,
            $inboundOnly
        );
    }

    // Across ALL of the person's channels (tenant-scoped): a session-scoped query missed messages
    // that landed on a different channel than the picked session.
    private function lastMessageAtForPerson(
        int $peopleId,
        int $appsId,
        int $companiesId,
        bool $inboundOnly
    ): ?Carbon {
        $channelIds = $this->personChannelIds($peopleId, $appsId, $companiesId);

        if ($channelIds->isEmpty()) {
            return null;
        }

        // No verb filter — `from_me = false` on a People-keyed channel is a strong-enough signal
        // that this is the customer's inbound, whatever channel it landed on.
        $lastMessageAt = Message::query()
            ->when($inboundOnly, fn ($query) => $query->where('message->from_me', false))
            ->where('is_deleted', 0)
            ->whereHas(
                'channels',
                fn ($q) => $q->whereIn('channels.id', $channelIds),
            )
            ->latest('created_at')
            ->value('created_at');

        return $lastMessageAt !== null ? Carbon::parse($lastMessageAt) : null;
    }

    /**
     * @return Collection<int, int>
     */
    public function personChannelIds(int $peopleId, int $appsId, int $companiesId): Collection
    {
        return Channel::query()
            ->where('entity_namespace', People::class)
            ->where('entity_id', $peopleId)
            ->where('is_deleted', 0)
            ->where('apps_id', $appsId)
            ->where('companies_id', $companiesId)
            ->pluck('id');
    }
}
