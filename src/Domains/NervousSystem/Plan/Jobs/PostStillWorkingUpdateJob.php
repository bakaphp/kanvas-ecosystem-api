<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
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
        public readonly Apps $app,
        public readonly Session $session,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

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
            ->where('apps_id', $this->session->apps_id)
            ->where('companies_id', $this->session->companies_id)
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
            $task = $this->runningTask($plan);
            $agent = $task?->agent?->name ?? $plan->agent?->name ?? 'An agent';
            $minutes = $task?->started_at !== null
                ? max(1, intdiv(now()->getTimestamp() - $task->started_at->getTimestamp(), 60))
                : null;

            return sprintf(
                '• %s: %s is on "%s"%s',
                (string) $plan->title,
                $agent,
                (string) $task?->title,
                $minutes !== null ? ' · ' . $minutes . ' min so far' : '',
            );
        });

        return "⏳ Still working on it. I'll tell you here when it's done.\n" . $lines->implode("\n");
    }

    /** The step itself, not the factory run under it, unless the run is all there is. */
    private function runningTask(Plan $plan): ?Task
    {
        $running = $plan->tasks()
            ->where('status', TaskStatusEnum::IN_PROGRESS->value)
            ->orderByRaw('parent_task_id IS NOT NULL')
            ->orderBy('started_at')
            ->first();

        return $running instanceof Task ? $running : null;
    }
}
