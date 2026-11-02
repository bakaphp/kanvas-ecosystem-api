<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Supervisor\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Intelligence\FollowUp\DataTransferObject\FollowUpConfig;
use Kanvas\Intelligence\FollowUp\Services\LeadFollowUpActivityService;
use Kanvas\Intelligence\FollowUp\Services\LeadFollowUpEligibilityService;
use Kanvas\Intelligence\Services\LeadConfigurationService;

/**
 * Lead types with continued follow-up on whose leads are due and get nothing. A lead only counts once
 * it passes the engine's own gates, so a muted, exhausted or waiting lead is never reported.
 */
final class SupervisorFollowUpAuditService
{
    private const int SENT_WINDOW_HOURS = 24;

    /** The dispatch is hourly: one that is merely due this tick is not a problem yet. */
    private const int OVERDUE_GRACE_MINUTES = 120;

    /** Each lead costs several queries in the gates, and this runs hourly per dealer. */
    private const int SAMPLE_LEADS = 50;

    private const int EVIDENCE_LEADS = 5;

    private readonly LeadFollowUpEligibilityService $eligibility;

    private readonly LeadFollowUpActivityService $activity;

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
    ) {
        $this->eligibility = new LeadFollowUpEligibilityService();
        $this->activity = new LeadFollowUpActivityService();
    }

    /**
     * @return list<array{code: string, severity: string, summary: string, evidence: array<string, mixed>}>
     */
    public function execute(): array
    {
        $configuration = new LeadConfigurationService();
        $leadTypes = LeadType::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', 0)
            ->get()
            ->filter(static fn (LeadType $leadType): bool => $configuration->hasContinuedFollowUpActive($leadType));

        if ($leadTypes->isEmpty()) {
            return [];
        }

        $to = Carbon::now();
        $from = $to->copy()->subHours(self::SENT_WINDOW_HOURS);
        $sentLeadIds = $this->activity->sentLeadIds(
            $this->app,
            $this->company,
            $from,
            $to
        );

        $findings = [];
        foreach ($leadTypes as $leadType) {
            $finding = $this->audit(
                $leadType,
                $sentLeadIds,
                $from,
                $to
            );

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * @param list<int> $sentLeadIds
     * @return array{code: string, severity: string, summary: string, evidence: array<string, mixed>}|null
     */
    private function audit(
        LeadType $leadType,
        array $sentLeadIds,
        Carbon $from,
        Carbon $to
    ): ?array {
        $candidates = $this->eligibility
            ->candidates($this->app, $this->company)
            ->where('leads_types_id', $leadType->getId());

        if (! $candidates->clone()->exists()) {
            $openLeads = $this->eligibility
                ->openLeads($this->app, $this->company)
                ->where('leads_types_id', $leadType->getId())
                ->count();

            return $openLeads === 0 ? null : $this->finding(
                "follow_up.lead_type_{$leadType->getId()}_no_enabled_stage",
                "Lead type {$leadType->name} has follow-up active, but none of its open leads sit in a stage with follow-up enabled.",
                ['lead_type_id' => $leadType->getId(), 'open_leads' => $openLeads],
            );
        }

        $sentForType = Lead::query()
            ->where('leads_types_id', $leadType->getId())
            ->whereIn('id', $sentLeadIds)
            ->exists();
        if ($sentForType) {
            return null;
        }

        $overdue = $this->overdue($candidates->clone()->orderByDesc('id')->limit(self::SAMPLE_LEADS)->get());
        if ($overdue->isEmpty()) {
            return null;
        }

        $leadIds = $overdue->modelKeys();

        return $this->finding(
            "follow_up.lead_type_{$leadType->getId()}_not_sending",
            "Lead type {$leadType->name} has follow-up active and leads are overdue, but none got a follow-up in the last "
                . self::SENT_WINDOW_HOURS . ' hours.',
            [
                'lead_type_id' => $leadType->getId(),
                'overdue_in_sample' => $overdue->count(),
                'sample_size' => self::SAMPLE_LEADS,
                'sample_lead_ids' => array_slice($leadIds, 0, self::EVIDENCE_LEADS),
                'recorded_reasons' => $this->activity->reasonsForLeads(
                    $this->app,
                    $this->company,
                    $leadIds,
                    $from,
                    $to
                ),
            ],
        );
    }

    /**
     * @param Collection<int, Lead> $leads
     * @return Collection<int, Lead>
     */
    private function overdue(Collection $leads): Collection
    {
        return $leads->filter(function (Lead $lead): bool {
            $config = FollowUpConfig::fromStage($lead->stage);

            return $this->eligibility->dispatchSkipReason($lead) === null
                && ! $this->eligibility->isDueForExhaustion($lead, $config)
                && $this->eligibility->silenceMinutes($lead) >= $config->timeBased->intervalMinutes + self::OVERDUE_GRACE_MINUTES;
        });
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array{code: string, severity: string, summary: string, evidence: array<string, mixed>}
     */
    private function finding(string $code, string $summary, array $evidence): array
    {
        return [
            'code' => $code,
            'severity' => SupervisorConfigurationAuditService::SEVERITY_HIGH,
            'summary' => $summary,
            'evidence' => $evidence,
        ];
    }
}
