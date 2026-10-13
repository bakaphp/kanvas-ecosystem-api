<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Support;

use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;

/**
 * Where a delegation was made from, read off the session the dispatching turn ran in.
 *
 * A worker's session is keyed on its Task and a plan wake's on its Plan, so the session is the one
 * place that says whether a new coding job or sub-task has a parent — and which task waits for it.
 * A user chat has neither; the outcome then goes back to the conversation the way it always has.
 */
final readonly class DelegationOrigin
{
    private function __construct(
        public ?Task $task,
        public ?Plan $plan,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null);
    }

    public static function fromSession(?Session $session): self
    {
        if ($session === null || ! $session->entity_id || ! $session->entity_namespace) {
            return self::none();
        }

        if ($session->entity_namespace === Task::class) {
            /** @var Task|null $task */
            $task = Task::query()
                ->whereKey((int) $session->entity_id)
                ->where('apps_id', $session->apps_id)
                ->where('is_deleted', 0)
                ->first();

            return new self($task, $task?->plan);
        }

        if ($session->entity_namespace === Plan::class) {
            /** @var Plan|null $plan */
            $plan = Plan::query()
                ->whereKey((int) $session->entity_id)
                ->where('apps_id', $session->apps_id)
                ->where('is_deleted', 0)
                ->first();

            return new self(null, $plan);
        }

        return self::none();
    }
}
