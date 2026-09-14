<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PiDev\Actions;

use Kanvas\Connectors\PiDev\Client;
use Kanvas\Connectors\PiDev\Enums\TaskCustomFieldEnum;
use Kanvas\Connectors\PiDev\Exceptions\PiDevApiException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Task;

class CancelCodingJobAction
{
    public function __construct(
        private readonly Task $task,
        private readonly ?Client $client = null,
    ) {
    }

    public function execute(): Task
    {
        if ($this->taskIsTerminal()) {
            return $this->task;
        }

        $jobId = $this->task->get(TaskCustomFieldEnum::PIDEV_JOB_ID->value);
        if (! is_string($jobId) || $jobId === '') {
            throw new ValidationException('This task has no pi.dev job to cancel');
        }

        try {
            $client = $this->client ?? new Client($this->task->app, $this->task->company);
            $client->cancelJob($jobId);
        } catch (PiDevApiException $e) {
            // 409 = pi.dev already finished this job; a cancel on a done job is expected, not a fault.
            if ($e->status !== 409) {
                throw $e;
            }
        }

        return $this->task;
    }

    private function taskIsTerminal(): bool
    {
        return TaskStatusEnum::tryFrom($this->task->status)?->isTerminal() ?? false;
    }
}
