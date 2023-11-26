<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleCondition;

class RuleConditionFactory extends Factory
{
    protected $model = RuleCondition::class;

    public function definition()
    {
        return [
           'rules_id' => Rule::factory()->create()->getId(),
           'attribute_name' => 'id',
           'operator' => '>=',
              'value' => 0,
        ];
    }
}
