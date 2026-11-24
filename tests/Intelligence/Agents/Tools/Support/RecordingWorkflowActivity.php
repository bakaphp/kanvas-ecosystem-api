<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools\Support;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

/**
 * Records which entities a rule ran for. DynamicRuleWorkflow builds the activity from its class name, so a
 * static list is the only way back out to the test.
 */
final class RecordingWorkflowActivity extends KanvasActivity implements WorkflowActivityInterface
{
    /** @var list<int> */
    public static array $entityIds = [];

    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        self::$entityIds[] = (int) $entity->getKey();

        return ['recorded' => true];
    }
}
