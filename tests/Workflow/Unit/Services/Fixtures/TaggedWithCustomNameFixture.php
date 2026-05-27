<?php

declare(strict_types=1);

namespace Tests\Workflow\Unit\Services\Fixtures;

use Kanvas\Workflow\Attributes\WorkflowAction;

#[WorkflowAction(name: 'Custom Display Name')]
class TaggedWithCustomNameFixture
{
}
