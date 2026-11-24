<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Services;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\LeadConversationTranscriptService;
use Kanvas\NervousSystem\Capability\Notifications\CapabilityGapReportedNotification;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Throwable;

/**
 * Emails the roadmap owners when a capability gap is filed or asked for again.
 *
 * Recipients are platform-wide by default — the roadmap is ours, not the tenant's. An app that sets
 * `capability_gap_alert_emails` replaces the platform list for its own gaps, the same precedence the
 * signup-anomaly alert uses.
 */
class CapabilityGapAlertService
{
    private const int TRANSCRIPT_LINES = 12;

    public function __construct(
        private readonly Apps $app,
    ) {
    }

    /**
     * @return list<string>
     */
    public function recipients(): array
    {
        $configured = $this->app->get(AppSettingsEnums::CAPABILITY_GAP_ALERT_EMAILS->getValue());

        return Str::emailList(
            $configured === null || $configured === ''
                ? config('kanvas.capability_gap.alert_emails', '')
                : $configured
        );
    }

    public function send(Plan $plan): void
    {
        $recipients = $this->recipients();

        if ($recipients === []) {
            return;
        }

        Notification::route('mail', $recipients)
            ->notify(new CapabilityGapReportedNotification($plan, $this->details($plan)));
    }

    /**
     * Everything the reader needs to judge the request without opening the app, as plain values —
     * the notification is queued, so the lead and transcript lookups happen here, once, not on the
     * worker.
     *
     * @return array<string, mixed>
     */
    public function details(Plan $plan): array
    {
        $input = is_array($plan->input) ? $plan->input : [];
        $request = is_array($input['last_request'] ?? null) ? $input['last_request'] : [];
        $agent = $plan->agent;
        $lead = $this->lead($plan, $request);

        return [
            'topic' => (string) ($input['topic'] ?? $plan->title),
            'context' => (string) ($request['context'] ?? $plan->description ?? ''),
            'why_not_fit' => (string) ($input['why_not_fit'] ?? ''),
            'considered_tools' => array_values((array) ($input['considered_tools'] ?? [])),
            'request_count' => (int) ($input['request_count'] ?? 1),
            'plan_id' => $plan->getId(),
            'plan_title' => $plan->title,
            'first_requested_at' => $this->readable($plan->created_at),
            'app_name' => (string) ($plan->app?->name ?? ''),
            'app_id' => $plan->apps_id,
            'company_name' => (string) ($plan->company?->name ?? ''),
            'company_id' => $plan->companies_id,
            'agent_name' => (string) ($agent?->name ?? ''),
            'agent_id' => $plan->agent_id,
            'agent_type' => (string) ($agent?->type?->name ?? ''),
            'requested_by_name' => (string) ($request['requested_by_name'] ?? ''),
            'requested_by_email' => (string) ($request['requested_by_email'] ?? ''),
            'channel' => (string) ($request['channel'] ?? ''),
            'requested_at' => $this->readable($request['requested_at'] ?? null),
            'lead_id' => $lead?->getId(),
            'lead_title' => (string) ($lead?->title ?? ''),
            'lead_url' => $lead?->adminUrl(),
            'transcript' => $lead !== null ? $this->transcript($lead) : [],
        ];
    }

    /**
     * @param array<string, mixed> $request
     */
    private function lead(Plan $plan, array $request): ?Lead
    {
        $leadId = (int) ($request['lead_id'] ?? 0);

        if ($leadId <= 0 || $plan->company === null || $plan->app === null) {
            return null;
        }

        try {
            /** @var Lead $lead */
            $lead = Lead::getByIdFromCompanyApp($leadId, $plan->company, $plan->app);

            return $lead;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Never lets a transcript problem swallow the alert: the gap is the signal, the transcript is
     * context.
     *
     * @return list<string>
     */
    private function transcript(Lead $lead): array
    {
        try {
            $lines = LeadConversationTranscriptService::lines($lead);
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        return array_slice($lines, -self::TRANSCRIPT_LINES);
    }

    private function readable(mixed $timestamp): ?string
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $timestamp)->toDayDateTimeString();
        } catch (Throwable) {
            return (string) $timestamp;
        }
    }
}
