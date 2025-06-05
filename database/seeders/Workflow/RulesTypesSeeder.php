<?php

namespace Database\Seeders\Workflow;

use Illuminate\Database\Seeder;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Models\RuleType;

class RulesTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        foreach (WorkflowEnum::cases() as $workflowType) {
            RuleType::firstOrCreate(
                ['name' => $workflowType->value]
            );
        }
    }
}
