<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Jobs;

use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\Agents\Models\AgentConversation;
use Kanvas\Intelligence\Agents\Models\AgentConversationMessage;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Jobs\Traits\AnnouncesPlanOutcome;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;

/**
 * Work a person asked for in a chat (research, a plan, a factory build) runs for many minutes, and the
 * chat stays silent until it ends, so a working agent and a stuck one look the same. After three silent
 * minutes the chat hears what is still running and for how long. The update is itself activity, so the
 * next one comes three minutes after it.
 */
class PostStillWorkingUpdateJob implements ShouldQueue
{
    use AnnouncesPlanOutcome;
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public const int SILENT_SECONDS = 180;

    public function __construct(
        public readonly Session $session,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->session->app);

        $plans = Plan::query()
            ->workingForAConversation()
            ->where('origin_session_id', $this->session->getId())
            ->orderBy('id')
            ->get();

        if ($plans->isEmpty() || ! $this->conversationIsSilent()) {
            return;
        }

        // Two sweeps can both find the chat silent before the first update has landed in it.
        if (! Cache::add('ns:still-working:' . $this->session->getId(), true, self::SILENT_SECONDS - 10)) {
            return;
        }

        $this->alsoPostToOriginConversation(
            $plans->first(),
            $this->update($plans),
            'plan-progress',
            mention: false,
        );
    }

    /**
     * Read off the transcript, which is what the chat renders. A session with no transcript has nothing
     * an update could appear in, so it gets none.
     */
    private function conversationIsSilent(): bool
    {
        $conversations = AgentConversation::query()
            ->fromApp($this->session->apps_id)
            ->fromCompany($this->session->companies_id)
            ->where('title', $this->session->uuid)
            ->select('id');

        $last = AgentConversationMessage::query()
            ->whereIn('conversation_id', $conversations)
            ->max('created_at');

        return $last !== null && Carbon::parse($last)->lte(now()->subSeconds(self::SILENT_SECONDS));
    }

    /**
     * @param Collection<int, Plan> $plans
     */
    private function update(Collection $plans): string
    {
        $lines = $plans->map(function (Plan $plan): string {
            $task = $this->workingTask($plan);
            $run = $task?->status === TaskStatusEnum::WAITING->value
                ? $task->children()->runningRecently()->latest('id')->first()
                : $task;
            $agent = $run?->agent?->name ?? $task?->agent?->name ?? $plan->agent?->name ?? 'An agent';
            $since = $run?->started_at ?? $run?->created_at;
            $minutes = $since !== null
                ? max(1, intdiv(now()->getTimestamp() - $since->getTimestamp(), 60))
                : null;

            $step = Str::trimToNull((string) $task?->title);

            if ($run !== null && $this->waitsOnAPerson($run)) {
                return sprintf(
                    '• %s: %s is waiting for your answer%s (the request is above)',
                    (string) $plan->title,
                    $agent,
                    $step !== null ? ' on "' . $step . '"' : '',
                );
            }

            return sprintf(
                '• %s: %s %s%s',
                (string) $plan->title,
                $agent,
                $step !== null ? 'is on "' . $step . '"' : 'is working on it',
                $minutes !== null ? ' · ' . $minutes . ' min so far' : '',
            );
        });

        return "⏳ Still working on it. I'll tell you here when it's done.\n" . $lines->implode("\n");
    }

    /** A run parked on a permission or a question is not working, and saying so hides the ask. */
    private function waitsOnAPerson(Task $run): bool
    {
        /** @var AgentTaskSession|null $session */
        $session = AgentTaskSession::query()->forTask($run->getId())->latest('id')->first();

        return $session?->harnessStatus()->isWaitingOnAHuman() ?? false;
    }

    /** The step on this plan itself, never a sub-step under it. */
    private function workingTask(Plan $plan): ?Task
    {
        /** @var Task|null */
        return $plan->tasks()
            ->workingNow()
            ->orderByRaw('parent_task_id IS NOT NULL')
            ->orderBy('id')
            ->first();
    }
}
