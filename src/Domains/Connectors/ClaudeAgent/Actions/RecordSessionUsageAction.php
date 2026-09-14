<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ClaudeAgent\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\ClaudeAgent\Services\AgentSpecBuilderService;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentUsageSnapshot;

/**
 * Fold a hosted session's reported spend into the agent's daily usage snapshot.
 *
 * Unlike every other backend, we do **not** price this ourselves: `ModelPricingCalculator` only
 * knows tokens, and a Managed Agents session is also billed for running time ($0.08/hour) and web
 * searches ($10/1k). Anthropic reports the authoritative total as `list_cost`, so that is what gets
 * stored — our own token maths would silently under-report every long-running task.
 *
 * `session.usage` is **cumulative for the session**, not per turn, so this keeps a per-session map in
 * `parsed_data` and recomputes the day's totals from it. Recording the same figure twice is a no-op,
 * which is what makes it safe to call on every drain pass rather than only at the end.
 */
class RecordSessionUsageAction
{
    /**
     * @param array<string, mixed>|null $usage The `session.usage` event payload, or null when the
     *        drain saw none this pass — a no-op, so callers never need to check first.
     */
    public function __construct(
        protected readonly Agent $agent,
        protected readonly string $sessionId,
        protected readonly ?array $usage,
        protected readonly ?Carbon $date = null,
    ) {
    }

    public function execute(): ?AgentUsageSnapshot
    {
        $entry = $this->entry();

        if ($entry === null || $this->sessionId === '') {
            return null;
        }

        $criteria = [
            'apps_id' => $this->agent->apps_id,
            'companies_id' => $this->agent->companies_id,
            'agent_id' => $this->agent->getId(),
            // Hosted agents have no deployment row — the whole point of the nullable agent_id.
            'agent_deployment_id' => null,
            'snapshot_date' => ($this->date ?? Carbon::now())->toDateString(),
            'source' => AgentProviderEnum::CLAUDE->value,
        ];

        // Locked because concurrent turns of the same agent both read-modify-write the session map,
        // and a lost update here silently under-reports the day.
        return DB::connection('intelligence')->transaction(function () use ($criteria, $entry): AgentUsageSnapshot {
            $snapshot = AgentUsageSnapshot::query()->where($criteria)->lockForUpdate()->first();

            $sessions = $snapshot?->parsed_data['sessions'] ?? [];
            $sessions = is_array($sessions) ? $sessions : [];
            $sessions[$this->sessionId] = $entry;

            $attributes = $this->totals($sessions) + [
                'provider' => 'anthropic',
                'model' => AgentSpecBuilderService::modelFor($this->agent),
                'parsed_data' => ['sessions' => $sessions],
            ];

            if (! $snapshot instanceof AgentUsageSnapshot) {
                return AgentUsageSnapshot::create($criteria + $attributes + ['raw_output' => '']);
            }

            $snapshot->update($attributes);

            return $snapshot;
        });
    }

    /**
     * @return array<string, mixed>|null Null when the payload carries nothing worth recording.
     */
    protected function entry(): ?array
    {
        if ($this->usage === null) {
            return null;
        }

        $cacheCreation = is_array($this->usage['cache_creation'] ?? null) ? $this->usage['cache_creation'] : [];

        $entry = [
            'input_tokens' => (int) ($this->usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($this->usage['output_tokens'] ?? 0),
            'cache_read_tokens' => (int) ($this->usage['cache_read_input_tokens'] ?? 0),
            'cache_write_tokens' => (int) ($cacheCreation['ephemeral_5m_input_tokens'] ?? 0)
                + (int) ($cacheCreation['ephemeral_1h_input_tokens'] ?? 0),
            // Whole cents as a string, per the API — kept in cents so no float rounding is applied
            // before the totals are summed.
            'cost_cents' => (int) ($this->usage['list_cost']['amount'] ?? 0),
            'active_seconds' => (float) ($this->usage['active_seconds'] ?? 0),
        ];

        return array_filter($entry) === [] ? null : $entry;
    }

    /**
     * @param array<string, mixed> $sessions
     * @return array<string, mixed>
     */
    protected function totals(array $sessions): array
    {
        $sum = static fn (string $key): int|float => array_sum(array_map(
            static fn (mixed $entry): int|float => is_array($entry) ? ($entry[$key] ?? 0) : 0,
            $sessions,
        ));

        $input = (int) $sum('input_tokens');
        $output = (int) $sum('output_tokens');

        return [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $input + $output,
            'cache_read_tokens' => (int) $sum('cache_read_tokens'),
            'cache_write_tokens' => (int) $sum('cache_write_tokens'),
            'cost_usd' => (int) $sum('cost_cents') / 100,
            'total_sessions' => count($sessions),
        ];
    }
}
