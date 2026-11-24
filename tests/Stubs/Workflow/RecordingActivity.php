<?php

declare(strict_types=1);

namespace Tests\Stubs\Workflow;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

/**
 * Stands in for any activity a rule hands an entity to: records what the engine passed so a test
 * can assert whether the rule fired and on which row. A subclass that redeclares `$calls` gets its
 * own ledger, so two tests in one process never read each other's calls.
 */
class RecordingActivity extends KanvasActivity implements WorkflowActivityInterface
{
    /** @var list<array{entity_id: int, params: array<string, mixed>}> */
    public static array $calls = [];

    public static function reset(): void
    {
        static::$calls = [];
    }

    /**
     * @return list<int>
     */
    public static function entityIds(): array
    {
        return array_column(static::$calls, 'entity_id');
    }

    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        static::$calls[] = ['entity_id' => (int) $entity->getKey(), 'params' => $params];

        return ['recorded' => true];
    }
}
