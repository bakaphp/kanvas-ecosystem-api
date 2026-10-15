<?php

declare(strict_types=1);

namespace Tests\Stubs\FollowUp;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

/**
 * Stands in for a CRM connector activity hung on `after-follow-up`: records what the rule
 * engine handed it so a test can assert the payload a real connector would receive.
 */
class RecordingAfterFollowUpActivity extends KanvasActivity implements WorkflowActivityInterface
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
