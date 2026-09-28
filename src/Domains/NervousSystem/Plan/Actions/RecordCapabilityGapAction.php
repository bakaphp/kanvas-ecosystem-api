<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions;

use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;

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
     */
    public function __construct(
        private readonly Agent $agent,
        private readonly string $topic,
        private readonly ?string $context = null,
        private readonly array $consideredTools = [],
        private readonly ?string $whyNotFit = null,
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

        if ($existing instanceof Plan) {
            return $this->bumpRequestCount($existing);
        }

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

        $plan->input = [...$input, 'request_count' => $count];
        $plan->saveQuietly();

        $plan->emitLedgerEvent('plan.capability_gap.requested_again', payload: [
            'topic' => $input['topic'] ?? null,
            'request_count' => $count,
            'agent_id' => $this->agent->getId(),
        ]);

        return $plan;
    }
}
