<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Models\RuleType;

class RuleTypeFactory extends Factory
{
    protected $model = RuleType::class;

    public function definition()
    {
        return [
           'name' => WorkflowEnum::CREATED->value,
        ];
    }
}
