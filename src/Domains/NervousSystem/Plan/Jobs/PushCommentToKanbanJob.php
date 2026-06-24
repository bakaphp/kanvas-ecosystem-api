<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\AgentRuntime\Contracts\AgentRuntimeProvider;
use Kanvas\Intelligence\AgentRuntime\Enums\KanbanCustomFieldEnum;
use Kanvas\Intelligence\AgentRuntime\Enums\KanbanStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Enums\KanbanTransition;
use Kanvas\Intelligence\AgentRuntime\Providers\AgentRuntimeProviderFactory;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Throwable;

/**
 * Push a human's Activities-channel comment to the agent's Hermes root card (the Kanvas → Hermes
 * half of the comment bridge). Tagged `kanvas:<uid>` so the ingest skips it. If the card is
 * `blocked` (the agent escalated), also `unblock` it so the dispatcher re-spawns the worker, which
 * replays the thread — including this guidance — and resumes. That's the "guide it to done" path.
 */
class PushCommentToKanbanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Plan $plan,
        public readonly string $content,
        public readonly int $authorUserId,
    ) {
        $this->onQueue('agent-runtime');
    }

    public function handle(): void
    {
        try {
            $agent = $this->plan->agent;

            if ($agent === null || trim($this->content) === '') {
                return;
            }

            $deployment = $agent->activeDeployment;

            if (! $deployment instanceof AgentDeployment
                || ! $deployment->isRunning()
                || ! AgentProviderEnum::forDeployment($deployment)->isHermes()
            ) {
                return;
            }

            $externalId = $this->plan->get(KanbanCustomFieldEnum::TASK_ID->value);

            // No card yet = the plan hasn't been pushed; nothing to comment on (the create path
            // carries the description, and the next plan-change push will create it).
            if (! is_string($externalId) || $externalId === '') {
                return;
            }

            $this->overwriteAppService($this->plan->app);

            $app = $this->plan->app;
            $company = $this->plan->company;
            $provider = $this->provider($deployment);

            $provider->commentKanbanTask(
                $deployment,
                $app,
                $company,
                $externalId,
                $this->content,
                KanbanCustomFieldEnum::KANVAS_AUTHOR_PREFIX . $this->authorUserId,
            );

            $storedStatus = $this->plan->get(KanbanCustomFieldEnum::STATUS->value);
            $current = KanbanStatusEnum::fromRuntime(is_string($storedStatus) ? $storedStatus : null);

            if ($current === KanbanStatusEnum::BLOCKED) {
                $result = $provider->transitionKanbanTask(
                    $deployment,
                    $app,
                    $company,
                    $externalId,
                    KanbanTransition::UNBLOCK,
                );

                $this->plan->set(KanbanCustomFieldEnum::STATUS->value, $result->status->value);
            }

            $this->plan->set(KanbanCustomFieldEnum::SYNCED_AT->value, Carbon::now()->toIso8601String());
        } catch (Throwable $e) {
            report($e);
        }
    }

    // Test seam — swap in a fake provider so the comment/unblock path runs without a runtime.
    protected function provider(AgentDeployment $deployment): AgentRuntimeProvider
    {
        return AgentRuntimeProviderFactory::forDeployment($deployment);
    }
}
