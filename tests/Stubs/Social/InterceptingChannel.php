<?php

declare(strict_types=1);

namespace Tests\Stubs\Social;

use Closure;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Workflow\SyncWorkflowStub;
use Override;

/**
 * A real channel row whose workflow announcements are observable.
 *
 * `fireWorkflow()` is a silent no-op without a matching `RuleType` row, so a test that wants to
 * assert what a burst announced — or to fail the announcement on purpose — has nothing to hook
 * otherwise. Wrapping keeps the real attributes so anything downstream that reads the row still
 * works.
 */
class InterceptingChannel extends Channel
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $fires = [];

    /**
     * Runs before the fire is recorded, for tests injecting a fault. Receives the event and params.
     */
    public ?Closure $onFire = null;

    public static function wrapping(Channel $channel): static
    {
        $intercepting = new static();
        $intercepting->setRawAttributes($channel->getAttributes(), true);
        $intercepting->exists = true;

        return $intercepting;
    }

    /**
     * @param array<string, mixed> $params
     */
    #[Override]
    public function fireWorkflow(
        string $event,
        bool $async = true,
        array $params = []
    ): ?SyncWorkflowStub {
        if ($this->onFire !== null) {
            ($this->onFire)($event, $params);
        }

        $this->fires[] = [$event, $params];

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function firstFireOf(string $event): ?array
    {
        foreach ($this->fires as [$fired, $params]) {
            if ($fired === $event) {
                return $params;
            }
        }

        return null;
    }
}
