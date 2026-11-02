<?php

declare(strict_types=1);

namespace Kanvas\Workflow;

use Baka\Contracts\AppInterface;
use Baka\Traits\KanvasJobsTrait;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Workflow\Services\ActivityRunRecorderService;
use Kanvas\Workflow\Support\TransientEntity;
use Kanvas\Workflow\Traits\ActivityIntegrationTrait;
use Override;
use Throwable;
use Workflow\Activity;

/**
 * @method array execute(Model $entity, AppInterface $app, array $params) defined by each concrete activity
 */
class KanvasActivity extends Activity
{
    use KanvasJobsTrait;
    use ActivityIntegrationTrait;

    public $queue = 'workflow';
    public $maxExceptions = 10;
    public ?Closure $onUnlock = null;

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHour();
    }

    /**
     * The queued (async) path. The run is recorded here rather than in the workflow because
     * workflow code replays from the top on every resume, so a write there would repeat. A throw
     * is not recorded here: the job is retried, and only the final failure reaches failed().
     */
    #[Override]
    public function handle()
    {
        if ($this->storedWorkflow->hasLogByIndex($this->index)) {
            return null;
        }

        $this->arguments = array_map(TransientEntity::unwrap(...), $this->arguments);

        $result = parent::handle();
        $this->recordRun($result);

        return $result;
    }

    #[Override]
    public function failed(Throwable $throwable): void
    {
        $this->recordRun(null, $throwable);

        parent::failed($throwable);
    }

    /**
     * The inline (sync) path: a rule run with `is_async = 0`, or the mutation running an activity
     * directly. Same record as the queued path.
     */
    public function executeRecorded(Model $entity, AppInterface $app, array $params): mixed
    {
        try {
            $result = $this->execute($entity, $app, $params);
        } catch (Throwable $exception) {
            new ActivityRunRecorderService()->record(
                $this,
                $entity,
                $app,
                $params,
                null,
                $exception
            );

            throw $exception;
        }

        new ActivityRunRecorderService()->record(
            $this,
            $entity,
            $app,
            $params,
            $result
        );

        return $result;
    }

    private function recordRun(mixed $result, ?Throwable $exception = null): void
    {
        [$entity, $app, $params] = array_pad(array_values($this->arguments), 3, null);

        if (! $entity instanceof Model || ! $app instanceof AppInterface) {
            return;
        }

        new ActivityRunRecorderService()->record(
            $this,
            $entity,
            $app,
            is_array($params) ? $params : [],
            $result,
            $exception
        );
    }
}
