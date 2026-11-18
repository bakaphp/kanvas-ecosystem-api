<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\AgentRuntime\Harness\Exceptions\CheckoutInUseException;
use Kanvas\Intelligence\AgentRuntime\Harness\HarnessFactory;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;

/**
 * A finished run's checkout, released again once the factory machine answers: until then its worktree
 * holds the branch, and the next run on that branch cannot create its own.
 */
class ReleaseFactoryCheckoutJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $tries = 10;

    public function __construct(
        public readonly Apps $app,
        public readonly int $sessionId,
    ) {
        $this->onQueue('coding');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 120, 300];
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $session = AgentTaskSession::findInApp($this->sessionId, $this->app);
        $factory = $session !== null ? HarnessFactory::codingFactoryFor($session) : null;

        if ($session === null || $factory === null || $session->repo_slug === null) {
            return;
        }

        // A transport failure throws, and the queue tries again after the backoff. A lock someone else
        // holds now is theirs to release, and will refuse every retry.
        try {
            $factory->release($session->repo_slug, $session->uuid);
        } catch (CheckoutInUseException) {
        }
    }
}
