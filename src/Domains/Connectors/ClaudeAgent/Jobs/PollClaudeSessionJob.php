<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ClaudeAgent\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\ClaudeAgent\Actions\AdvanceLongTaskAction;
use Kanvas\NervousSystem\Plan\Models\Task;

/**
 * Ticks one hosted session forward. Thin on purpose — {@see AdvanceLongTaskAction} holds the logic
 * so it can be tested with an injected Client, which a serialized job cannot carry.
 */
final class PollClaudeSessionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    private const int POLL_INTERVAL_SECONDS = 30;

    public function __construct(
        public readonly Apps $app,
        public readonly int $taskId,
    ) {
        $this->onQueue('agent-runtime');
    }

    public function handle(): void
    {
        // FIRST LINE, always. The worker is long-lived and Bouncer's scope is process-global, so
        // without this the previous job's scope leaks into every Role lookup the tool bridge makes,
        // surfacing as a ModelNotFoundException far from its cause.
        $this->overwriteAppService($this->app);

        /** @var Task $task */
        $task = Task::getById($this->taskId, $this->app);

        if (new AdvanceLongTaskAction($task)->execute()) {
            self::dispatch($this->app, $this->taskId)->delay(now()->addSeconds(self::POLL_INTERVAL_SECONDS));
        }
    }
}
