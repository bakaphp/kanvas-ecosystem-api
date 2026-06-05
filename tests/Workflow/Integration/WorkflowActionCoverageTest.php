<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration;

use Kanvas\Workflow\Services\WorkflowActionDiscoveryService;
use Tests\TestCase;

final class WorkflowActionCoverageTest extends TestCase
{
    public function testEveryActivityAndWebhookJobCarriesTheWorkflowActionAttribute(): void
    {
        $missing = new WorkflowActionDiscoveryService()->findUntagged();

        $this->assertSame(
            [],
            $missing,
            "The following KanvasActivity / ProcessWebhookJob subclasses are missing the "
            . "#[WorkflowAction] attribute and will not appear in the rules UI:\n  - "
            . implode("\n  - ", $missing)
        );
    }
}
