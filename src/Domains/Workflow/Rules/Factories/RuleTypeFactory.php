<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Enums\RuleTypeEnum;
use Kanvas\Workflow\Rules\Models\RuleType;

class RuleTypeFactory extends Factory
{
    protected $model = RuleType::class;

    public function definition()
    {
        return [
           'name' => RuleTypeEnum::CREATED->value,
        ];
    }
}
