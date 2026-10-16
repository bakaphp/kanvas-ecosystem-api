<?php

declare(strict_types=1);

namespace Tests\Stubs\Organizations;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

/**
 * Stands in for a connector activity hung on Organization `created` (e.g. PushOrganizationActivity):
 * records what the rule engine handed it so a test can assert whether it fired at all.
 */
class RecordingOrganizationCreatedActivity extends KanvasActivity implements WorkflowActivityInterface
{
    /** @var list<array{entity_id: int, params: array<string, mixed>}> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }

    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        self::$calls[] = ['entity_id' => (int) $entity->getKey(), 'params' => $params];

        return ['recorded' => true];
    }
}
