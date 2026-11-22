<?php

declare(strict_types=1);

namespace Tests\Event\Support;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Event\Passes\Services\PassScanDecisionGate;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;
use RuntimeException;

#[WorkflowAction(name: 'Test Pass Scan Decision')]
class PassScanDecisionTestActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        return match ($params['verdict'] ?? null) {
            'allow' => [PassScanDecisionGate::DECISION_KEY => ['allowed' => true]],
            'deny' => [PassScanDecisionGate::DECISION_KEY => ['allowed' => false]],
            'truthy' => [PassScanDecisionGate::DECISION_KEY => ['allowed' => 'yes']],
            'boom' => throw new RuntimeException('provider down'),
            default => ['unrelated' => true],
        };
    }
}
