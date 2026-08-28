<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Jobs\Traits\AnnouncesPlanOutcome;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\Social\Channels\Models\Channel;

/**
 * Tell the person who asked for the work that it is finished.
 *
 * The blocked half of this already existed; the done half did not, so a plan that succeeded ended in
 * silence. The agent posts its summary to the plan's Activities channel — which nobody watches, by
 * the PM's own instructions — and the only ways to find out were to open the board or ask. Work that
 * finishes without telling anyone is indistinguishable from work that never ran.
 *
 * Deliberately a nudge and not a report: the person wanted to stop checking, not to read a digest.
 * What each task actually produced is on the plan, one click away, and reproducing it here would make
 * the notification the thing that has to be read.
 */
class NotifyPlanOwnerOfCompletedPlanJob implements ShouldQueue
{
    use AnnouncesPlanOutcome;
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    /** One "finished" per plan per window, however many times it is saved while done. */
    private const int THROTTLE_MINUTES = 60;

    private const int SUMMARY_TASKS = 3;

    /**
     * Per-task result length in the conversation copy. Long enough to carry an answer — a count, a
     * URL, a filename — without turning a chat message into the plan's whole transcript.
     */
    private const int RESULT_CHAR_CAP = 400;

    public function __construct(
        public readonly Plan $plan,
    ) {
    }

    public function handle(): void
    {
        $plan = $this->plan->refresh();

        // It can reopen inside the settle delay — an agent that closed a plan and then found more to
        // do would otherwise announce a finish that has already stopped being true.
        if ($plan->status !== PlanStatusEnum::DONE->value) {
            return;
        }

        $owner = $plan->user;

        if ($owner === null) {
            return;
        }

        $this->overwriteAppService($plan->app);

        if (! Cache::add('ns:plan:' . $plan->getId() . ':done-alert', true, now()->addMinutes(self::THROTTLE_MINUTES))) {
            return;
        }

        // Built without a mention: each destination addresses a different person — the plan's own
        // board names its owner, the asker's conversation names the asker.
        $body = sprintf(
            '✅ Done: %s%s Nothing needed from you — open the plan if you want the detail.',
            $plan->title,
            $this->whatGotDone($plan),
        );

        $this->postToPlanBoard(
            $plan,
            $body,
            'plan-done-alert',
            'plan_done',
        );

        // The conversation gets the ANSWER, not just the fact it finished. That is where the work was
        // asked for, so the results belong there — otherwise the person is told it is done and still
        // has to go and look for what it produced. The board and the notification keep the short form.
        $this->alsoPostToOriginConversation($plan, $body . $this->resultsDigest($plan), 'plan-done-alert');

        // The mention above can be dropped for an agent-classified user; this reaches the person
        // who asked regardless, and costs no model tokens.
        $this->notifyTheAsker(
            $plan,
            'Finished',
            sprintf('%s is done.%s', $plan->title, $this->whatGotDone($plan))
        );
    }

    /**
     * What each finished task actually returned, for the conversation copy.
     *
     * `RunTaskWorkerJob` records the worker's own answer on the task. Reporting only that a plan
     * finished, when the numbers are sitting right there, sends the person off to find them — the same
     * dead end as the file whose URL was on a task nobody could read.
     */
    private function resultsDigest(Plan $plan): string
    {
        $results = $plan->tasks()
            ->where('is_deleted', 0)
            ->where('status', 'done')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->map(function (Task $task): ?string {
                $summary = $task->workerSummaryExcerpt(self::RESULT_CHAR_CAP);

                return $summary === null ? null : sprintf(
                    '%s: %s',
                    Str::limit((string) $task->title, 60),
                    $summary,
                );
            })
            ->filter()
            ->values();

        return $results->isEmpty() ? '' : "\n\n" . $results->implode("\n");
    }

    /**
     * Enough for the reader to recognise the work without opening it — the task titles, not their
     * output. A count alone ("3 tasks done") names nothing they asked for.
     */
    private function whatGotDone(Plan $plan): string
    {
        $tasks = $plan->tasks()
            ->where('is_deleted', 0)
            ->where('status', 'done')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        if ($tasks->isEmpty()) {
            return '';
        }

        $titles = $tasks->take(self::SUMMARY_TASKS)
            ->map(fn (Task $task): string => Str::limit((string) $task->title, 60))
            ->implode('; ');

        $rest = $tasks->count() - min($tasks->count(), self::SUMMARY_TASKS);

        return sprintf(
            ' — %s%s.',
            $titles,
            $rest > 0 ? sprintf(' and %d more', $rest) : '',
        );
    }
}
