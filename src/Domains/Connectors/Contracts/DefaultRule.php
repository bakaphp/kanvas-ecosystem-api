<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Contracts;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\KanvasActivity;
use Kanvas\Workflow\Rules\DataTransferObject\RuleConditionData;

/**
 * One rule an integration asks for when a company connects it: on `$trigger` for `$model`, run
 * `$activity`. The name is the identity the rule is created and removed by, so keep it stable.
 */
final readonly class DefaultRule
{
    /**
     * @param class-string<Model> $model
     * @param class-string<KanvasActivity> $activity
     * @param array<string, mixed> $params
     * @param list<RuleConditionData> $conditions
     */
    public function __construct(
        public WorkflowEnum $trigger,
        public string $model,
        public string $activity,
        public string $name,
        public bool $isAsync = false,
        public array $params = [],
        public array $conditions = [],
    ) {
    }
}
