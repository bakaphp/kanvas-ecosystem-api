<?php

declare(strict_types=1);

namespace Tests\Event\Support;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction(name: 'Test Pass Scanned Recorder')]
class PassScannedRecorderTestActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public static array $calls = [];

    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        self::$calls[] = ['entity_id' => $entity->getKey(), 'params' => $params];

        return ['recorded' => true];
    }
}
