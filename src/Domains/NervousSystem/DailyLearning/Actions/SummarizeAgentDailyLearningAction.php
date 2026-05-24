<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\DailyLearning\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\DailyLearningSummary;
use Kanvas\Intelligence\AgentRuntime\Providers\AgentRuntimeProviderFactory;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentConversation;
use Kanvas\Intelligence\Agents\Models\AgentDailyCycle;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\NervousSystem\DailyLearning\Services\DailyLearningPromptBuilderService;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

use function Laravel\Ai\agent;

/**
 * Core daily-learning action. Reads the agent's conversations for the date,
 * calls an LLM with structured-output schema, persists the summary into
 * AgentDailyCycle, pushes durable facts back to the agent's own memory bank
 * via the AgentRuntimeProvider seam, then emits a ledger event.
 *
 * Two no-op gates:
 *  - $dryRun  → run everything except the DB write, the memory push, and
 *               the ledger emit. Returns the parsed DailyLearningSummary.
 *  - $skipPush→ persist + ledger, but skip the runtime memory push. Useful
 *               for "verify the briefing quality before touching the agent".
 */
class SummarizeAgentDailyLearningAction
{
    public function __construct(
        protected readonly Agent $agent,
        protected readonly AppInterface $app,
        protected readonly Companies $company,
        protected readonly Carbon $cycleDate,
        protected readonly bool $dryRun = false,
        protected readonly bool $skipPush = false,
        protected readonly string $model = 'gemini-2.5-pro',
    ) {
    }

    public function execute(): ?DailyLearningSummary
    {
        $conversations = $this->loadConversations();
        if ($conversations->isEmpty()) {
            return null;
        }

        $prompt = new DailyLearningPromptBuilderService()->build(
            $this->agent,
            $this->cycleDate->toDateString(),
            $conversations,
        );

        $summary = $this->callLlm($prompt);

        if ($this->dryRun) {
            return $summary;
        }

        $this->persistCycle($summary);

        $pushed = false;
        if (! $this->skipPush) {
            $pushed = $this->pushToRuntime($summary);
        }

        $this->emitLedger($summary, $conversations->count(), $pushed);

        return $summary;
    }

    /**
     * @return Collection<int, AgentConversation>
     */
    private function loadConversations(): Collection
    {
        $timezone = $this->resolveTimezone();
        // Re-parse from the YMD label so the date is anchored *in* the target
        // tz rather than shifted into it — `setTimezone()` rotates the moment,
        // which slides the day window backward for west-of-UTC zones.
        $cycleLabel = $this->cycleDate->toDateString();
        $dayStart = Carbon::parse($cycleLabel, $timezone)->startOfDay()->utc();
        $dayEnd = Carbon::parse($cycleLabel, $timezone)->endOfDay()->utc();

        return AgentConversation::query()
            ->where('agent_id', $this->agent->getId())
            ->where('apps_id', (int) $this->app->getId())
            ->where('companies_id', (int) $this->company->getId())
            ->whereHas(
                'messages',
                fn ($q) => $q->whereBetween('created_at', [$dayStart, $dayEnd])
            )
            ->with(['messages' => fn ($q) => $q->whereBetween('created_at', [$dayStart, $dayEnd])])
            ->orderBy('created_at')
            ->get();
    }

    private function callLlm(string $prompt): DailyLearningSummary
    {
        /** @var StructuredAgentResponse $response */
        $response = agent(
            schema: fn ($schema): array => [
                'briefing' => $schema->string()
                    ->description('2-3 paragraph first-person narrative of what the agent did yesterday')
                    ->required(),
                'proposed_actions' => $schema->array()
                    ->description("Today's concrete todo items (3-8 short imperative sentences)")
                    ->items($schema->string()),
                'durable_facts' => $schema->array()
                    ->description('One-line declarative facts useful 30+ days. Must NOT include ephemeral observations.')
                    ->items($schema->string()),
                'skills_emerged' => $schema->array()
                    ->description('Capability patterns the agent demonstrated')
                    ->items($schema->object([
                        'name' => $schema->string()->required(),
                        'confidence' => $schema->number()->required(),
                    ])),
                'self_improvement_score' => $schema->number()
                    ->description('0.0 to 0.5 — judgment of capability improvement')
                    ->required(),
            ],
        )->prompt(
            $prompt,
            provider: Lab::Gemini,
            model: $this->model,
            timeout: 220,
        );

        return DailyLearningSummary::from($response->structured);
    }

    private function persistCycle(DailyLearningSummary $summary): void
    {
        AgentDailyCycle::updateOrCreate(
            [
                'agent_id' => $this->agent->getId(),
                'cycle_date' => $this->cycleDate->toDateString(),
            ],
            [
                'apps_id' => (int) $this->app->getId(),
                'companies_id' => (int) $this->company->getId(),
                'morning_briefing' => $summary->briefing,
                'proposed_actions' => $summary->proposed_actions,
                'skills_emerged' => $summary->skills_emerged,
                'durable_facts' => $summary->durable_facts,
                'self_improvement_score' => $summary->self_improvement_score,
                'signed_by_text' => sprintf(
                    '— %s, signing in',
                    $this->agent->name !== '' ? $this->agent->name : ('agent-' . (int) $this->agent->getId()),
                ),
            ],
        );
    }

    private function pushToRuntime(DailyLearningSummary $summary): bool
    {
        $deployment = $this->resolveActiveDeployment();
        if (! $deployment instanceof AgentDeployment) {
            return false;
        }

        try {
            return AgentRuntimeProviderFactory::forDeployment($deployment)
                ->pushDailyLearningContext($deployment, $summary, $this->cycleDate);
        } catch (Throwable $e) {
            // Push is best-effort feedback — AgentDailyCycle already persisted.
            // Don't fail the action; surface the failure to ops.
            Log::warning('Daily learning push to runtime failed', [
                'agent_id' => $this->agent->getId(),
                'deployment_id' => $deployment->getId(),
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function resolveActiveDeployment(): ?AgentDeployment
    {
        return $this->agent->activeDeployment;
    }

    private function emitLedger(DailyLearningSummary $summary, int $conversationCount, bool $pushed): void
    {
        new AppendEventAction(new EventData(
            app: $this->app,
            company: $this->company,
            sourceDomain: 'NervousSystem.DailyLearning',
            eventType: 'agent.daily_learning.summarized',
            status: EventStatusEnum::SUCCESS,
            sourceEntityType: Agent::class,
            sourceEntityId: $this->agent->getId(),
            actorType: 'System',
            payload: [
                'cycle_date' => $this->cycleDate->toDateString(),
                'conversation_count' => $conversationCount,
                'durable_facts_count' => count($summary->durable_facts),
                'skills_emerged_count' => count($summary->skills_emerged),
                'self_improvement_score' => $summary->self_improvement_score,
                'model' => $this->model,
                'pushed_to_runtime' => $pushed,
            ],
        ))->execute();
    }

    /**
     * Company timezone → app timezone → UTC, in that order. Companies.timezone
     * is a real DB column (nullable in practice despite the optimistic
     * `@property string` docblock); Apps has no timezone column so its
     * value comes from custom_fields via ->get().
     */
    private function resolveTimezone(): string
    {
        /** @psalm-suppress RedundantCastGivenDocblockType */
        $companyTz = (string) $this->company->timezone;
        if ($companyTz !== '') {
            return $companyTz;
        }

        $appTz = $this->app->get('timezone');
        if (is_string($appTz) && $appTz !== '') {
            return $appTz;
        }

        return 'UTC';
    }
}
