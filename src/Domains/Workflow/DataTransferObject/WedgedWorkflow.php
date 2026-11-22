<?php

declare(strict_types=1);

namespace Kanvas\Workflow\DataTransferObject;

use Kanvas\Workflow\Enums\WedgedWorkflowCauseEnum;
use Workflow\Models\StoredWorkflow;

final readonly class WedgedWorkflow
{
    /**
     * @param list<string> $activityLocks the lock keys the overlap middleware still counts as held
     */
    public function __construct(
        public StoredWorkflow $storedWorkflow,
        public string $queue,
        public int $attempts,
        public array $activityLocks,
        public WedgedWorkflowCauseEnum $cause
    ) {
    }
}
