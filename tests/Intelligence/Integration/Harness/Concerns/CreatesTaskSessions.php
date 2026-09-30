<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness\Concerns;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;

trait CreatesTaskSessions
{
    /**
     * A saved opencode session on the current app with no endpoint, so anything that reaches for the
     * runtime fails fast and is reported rather than opening a connection.
     *
     * @param array<string, mixed> $attributes
     */
    protected function createTaskSession(
        HarnessStatusEnum $status = HarnessStatusEnum::RUNNING,
        array $attributes = []
    ): AgentTaskSession {
        $session = new AgentTaskSession();
        $session->apps_id = app(Apps::class)->getId();
        $session->companies_id = 0;
        $session->task_id = random_int(900000, 999999);
        $session->harness = HarnessEnum::OPENCODE->value;
        $session->status = $status->value;
        $session->fill($attributes);
        $session->saveOrFail();

        return $session;
    }
}
