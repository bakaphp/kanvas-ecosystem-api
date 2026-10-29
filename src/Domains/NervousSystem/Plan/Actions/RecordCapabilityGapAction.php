<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Capability\Services\CapabilityGapAlertService;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\Users\Models\Users;

/**
 * Turns "nobody has built this" into a row somebody owns.
 *
 * Recorded as a Plan rather than a bespoke table so it inherits what plans already have — an
 * Activities channel, an owner, ledger events, and the approval gate. A roadmap request from an
 * agent then lands in the same place as everything else the agent surfaces, instead of a queue only
 * one screen knows about.
 *
 * Deduped by topic, because the interesting signal is "asked for repeatedly", not "asked for once
 * per turn". A recurring gap bumps a counter on the existing plan rather than filing a new one.
 *
 * The tool that calls this searches the catalog first and refuses to file when related tools exist
 * and the caller cannot say why they do not fit — a gap filed for a tool the agent already holds is
 * worse than no gap, because someone then builds it twice. What was considered is recorded here so
 * whoever picks the item up can see the argument rather than re-deriving it.
 */
class RecordCapabilityGapAction
{
    public const string PLAN_TYPE = 'capability_gap';

    /**
     * @param list<string> $consideredTools Related tools the caller was shown before filing.
     * @param string|null $whyNotFit Why none of them cover this — recorded so whoever reads the
     *        roadmap item sees the argument, not just the request.
     * @param Users|null $requestedBy The person whose ask this was, when a human was at the keyboard —
     *        not the agent's own user, which is who files it.
     * @param Session|null $session The conversation the ask came from.
     * @param Lead|null $lead The record the conversation was about, when there was one.
     */
    public function __construct(
        private readonly Agent $agent,
        private readonly string $topic,
        private readonly ?string $context = null,
        private readonly array $consideredTools = [],
        private readonly ?string $whyNotFit = null,
        private readonly ?Users $requestedBy = null,
        private readonly ?Session $session = null,
        private readonly ?Lead $lead = null,
    ) {
    }

    public function execute(): Plan
    {
        $topic = trim($this->topic);
        $slug = Str::slug(Str::limit(strtolower($topic), 80, ''));

        $existing = Plan::query()
            ->where('apps_id', $this->agent->apps_id)
            ->where('companies_id', $this->agent->companies_id)
            ->where('plan_type', self::PLAN_TYPE)
            ->where('is_deleted', 0)
            ->whereIn('status', [PlanStatusEnum::INTAKE->value, PlanStatusEnum::DRAFT->value])
            ->get()
            ->first(fn (Plan $plan): bool => ($plan->input['topic_slug'] ?? null) === $slug);

        $plan = $existing instanceof Plan
            ? $this->bumpRequestCount($existing)
            : $this->file($topic, $slug);

        new CapabilityGapAlertService($this->agent->app)->send($plan);

        return $plan;
    }

    private function file(string $topic, string $slug): Plan
    {
        $plan = Plan::create([
            'apps_id' => $this->agent->apps_id,
            'companies_id' => $this->agent->companies_id,
            'users_id' => $this->agent->users_id,
            'agent_id' => $this->agent->getId(),
            'plan_type' => self::PLAN_TYPE,
            'title' => Str::limit('Capability gap: ' . $topic, 120),
            'description' => $this->context,
            // DRAFT, not INTAKE: the gap itself is fully specified — a human decides whether to build
            // it, which is a roadmap call rather than an unanswered question.
            'status' => PlanStatusEnum::DRAFT->value,
            'priority' => 0,
            'completion_pct' => 0,
            'wake_count' => 0,
            'input' => [
                'topic' => $topic,
                'topic_slug' => $slug,
                'request_count' => 1,
                'first_requested_by_agent_id' => $this->agent->getId(),
                'considered_tools' => $this->consideredTools,
                'why_not_fit' => $this->whyNotFit,
                'last_request' => $this->lastRequest(),
            ],
        ]);

        $plan->emitLedgerEvent('plan.capability_gap.recorded', payload: [
            'topic' => $topic,
            'agent_id' => $this->agent->getId(),
            'considered_tools' => $this->consideredTools,
        ]);

        return $plan;
    }

    private function bumpRequestCount(Plan $plan): Plan
    {
        $input = is_array($plan->input) ? $plan->input : [];
        $count = (int) ($input['request_count'] ?? 1) + 1;

        $plan->input = [...$input, 'request_count' => $count, 'last_request' => $this->lastRequest()];
        $plan->saveQuietly();

        $plan->emitLedgerEvent('plan.capability_gap.requested_again', payload: [
            'topic' => $input['topic'] ?? null,
            'request_count' => $count,
            'agent_id' => $this->agent->getId(),
        ]);

        return $plan;
    }

    /**
     * Who asked, from where, and what for — kept on the plan so a repeat overwrites it with the
     * latest ask, and the alert email can say more than the topic.
     *
     * @return array<string, mixed>
     */
    private function lastRequest(): array
    {
        return [
            'requested_at' => Carbon::now()->toIso8601String(),
            'context' => $this->context,
            'requested_by_users_id' => $this->requestedBy?->getId(),
            'requested_by_name' => $this->requestedBy?->fullName(),
            'requested_by_email' => $this->requestedBy?->email,
            'session_id' => $this->session?->getId(),
            'channel' => $this->session?->channel?->name ?? $this->session?->getChannel(),
            'lead_id' => $this->lead?->getId(),
        ];
    }
}
