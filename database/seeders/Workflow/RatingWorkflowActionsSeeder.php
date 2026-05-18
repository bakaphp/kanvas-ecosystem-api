<?php

declare(strict_types=1);

namespace Database\Seeders\Workflow;

use Illuminate\Database\Seeder;
use Kanvas\Inventory\Products\WorkflowActivity\SetProductVariantsSortingRatingFromCategoryActivity;
use Kanvas\Inventory\Variants\WorkflowActivity\SetVariantSortingRatingFromCategoryActivity;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\RuleWorkflowAction;

class RatingWorkflowActionsSeeder extends Seeder
{
    public function run(): void
    {
        $activities = [
            [
                'name' => 'Set Variant Sorting Rating From Category',
                'model_name' => SetVariantSortingRatingFromCategoryActivity::class,
            ],
            [
                'name' => 'Set Product Variants Sorting Rating From Category',
                'model_name' => SetProductVariantsSortingRatingFromCategoryActivity::class,
            ],
        ];

        foreach ($activities as $activityData) {
            $action = Action::firstOrCreate(
                ['model_name' => $activityData['model_name']],
                ['name' => $activityData['name']],
            );

            RuleWorkflowAction::firstOrCreate(
                ['actions_id' => $action->id],
            );
        }
    }
}
