<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Models\RuleType;
use Override;

class RuleTypeFactory extends Factory
{
    protected $model = RuleType::class;

    #[Override]
    public function definition()
    {
        return [
           'name' => WorkflowEnum::CREATED->value,
        ];
    }

    public function created()
    {
        return $this->state(function (array $attributes) {
            return [
                'name' => WorkflowEnum::CREATED->value,
            ];
        });
    }

    public function creating()
    {
        return $this->state(function (array $attributes) {
            return [
                'name' => WorkflowEnum::CREATING->value,
            ];
        });
    }

    public function updated()
    {
        return $this->state(function (array $attributes) {
            return [
                'name' => WorkflowEnum::UPDATED->value,
            ];
        });
    }

    public function updating()
    {
        return $this->state(function (array $attributes) {
            return [
                'name' => WorkflowEnum::UPDATING->value,
            ];
        });
    }

    public function deleted()
    {
        return $this->state(function (array $attributes) {
            return [
                'name' => WorkflowEnum::DELETED->value,
            ];
        });
    }
}
