<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Services;

use Illuminate\Support\Facades\DB;
use Kanvas\Intelligence\Agents\Services\ModelPricingCalculator;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Plan\Enums\PlanLoopConfigEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Throwable;

/**
 * What one plan has cost so far.
 *
 * Every wake for a plan lands on that plan's own Session, and the conversation store keys the
 * conversation by that session's uuid — so summing usage across the conversation's messages is the
 * per-plan figure. `agent_usage_snapshots` cannot answer this: it aggregates by agent and day, which
 * is the right grain for a monthly bill and the wrong one for "did this plan run away".
 *
 * Enforcement only, not measurement — the token capture and the pricing table already existed.
 */
class PlanBudgetService
{
    private const float DEFAULT_WARN_AT_FRACTION = 0.8;

    /**
     * @return array{tokens: int, cost_usd: float}
     */
    public function spend(Plan $plan): array
    {
        $session = Session::query()
            ->where('apps_id', $plan->apps_id)
            ->where('companies_id', $plan->companies_id)
            ->where('entity_namespace', Plan::class)
            ->where('entity_id', $plan->getId())
            ->first();

        if ($session === null) {
            return $this->nothing();
        }

        try {
            $row = DB::connection('intelligence')
                ->table('agent_conversation_messages as m')
                ->where('m.conversation_id', $session->uuid)
                ->selectRaw("COALESCE(SUM(CAST(COALESCE(JSON_EXTRACT(m.`usage`, '$.prompt_tokens'), JSON_EXTRACT(m.`usage`, '$.input_tokens'), 0) AS UNSIGNED)), 0) as input_tokens")
                ->selectRaw("COALESCE(SUM(CAST(COALESCE(JSON_EXTRACT(m.`usage`, '$.completion_tokens'), JSON_EXTRACT(m.`usage`, '$.output_tokens'), 0) AS UNSIGNED)), 0) as output_tokens")
                ->first();

            $model = $this->dominantModel($session->uuid);
        } catch (Throwable) {
            // An unreadable usage blob must not stop a plan that would otherwise run. Zero spend
            // means "no opinion", and fails open — the wake budget is the backstop that fails closed.
            return $this->nothing();
        }

        $input = (int) ($row->input_tokens ?? 0);
        $output = (int) ($row->output_tokens ?? 0);

        return [
            'tokens' => $input + $output,
            'cost_usd' => app(ModelPricingCalculator::class)->costFor(
                provider: $model !== null ? ModelPricingCalculator::inferProvider($model) : null,
                model: $model,
                inputTokens: $input,
                outputTokens: $output,
            ),
        ];
    }

    /** The configured ceiling in USD, or null when this app has not set one — which is the default. */
    public function capUsd(Plan $plan): ?float
    {
        $cap = $plan->app->get(PlanLoopConfigEnum::MAX_COST_USD->value);

        return is_numeric($cap) && (float) $cap > 0 ? (float) $cap : null;
    }

    /** A reason when the plan is over its ceiling; null when it is under, or has none. */
    public function exceededReason(Plan $plan): ?string
    {
        $cap = $this->capUsd($plan);

        if ($cap === null) {
            return null;
        }

        $cost = $this->spend($plan)['cost_usd'];

        return $cost >= $cap
            ? sprintf('Plan spend $%.2f reached its $%.2f ceiling.', $cost, $cap)
            : null;
    }

    /** Crossed the warn threshold but not yet the cap — the state worth telling the owner about. */
    public function shouldWarn(Plan $plan): bool
    {
        $cap = $this->capUsd($plan);

        if ($cap === null) {
            return false;
        }

        $configured = $plan->app->get(PlanLoopConfigEnum::WARN_AT_FRACTION->value);
        $fraction = is_numeric($configured) && (float) $configured > 0 && (float) $configured < 1
            ? (float) $configured
            : self::DEFAULT_WARN_AT_FRACTION;

        $cost = $this->spend($plan)['cost_usd'];

        return $cost >= $cap * $fraction && $cost < $cap;
    }

    /**
     * Pricing needs a model name and usage blobs carry one per turn; a plan that switched models
     * mid-flight is priced at whichever it used most, which is close enough for a ceiling.
     */
    private function dominantModel(string $conversationId): ?string
    {
        $row = DB::connection('intelligence')
            ->table('agent_conversation_messages as m')
            ->where('m.conversation_id', $conversationId)
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(m.`usage`, '$.model')) as model, COUNT(*) as turns")
            ->whereRaw("JSON_EXTRACT(m.`usage`, '$.model') IS NOT NULL")
            ->groupBy('model')
            ->orderByDesc('turns')
            ->first();

        $model = $row->model ?? null;

        return is_string($model) && $model !== '' ? $model : null;
    }

    /**
     * @return array{tokens: int, cost_usd: float}
     */
    private function nothing(): array
    {
        return ['tokens' => 0, 'cost_usd' => 0.0];
    }
}
