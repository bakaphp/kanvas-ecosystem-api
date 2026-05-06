<?php

declare(strict_types=1);

namespace Database\Seeders\Workflow;

use Illuminate\Database\Seeder;
use Kanvas\Inventory\Products\WorkflowActivity\SyncProductVariantsRatingFromCategoryActivity;
use Kanvas\Inventory\Variants\WorkflowActivity\SyncVariantRatingFromCategoryActivity;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\RuleWorkflowAction;

class RatingWorkflowActionsSeeder extends Seeder
{
    public function run(): void
    {
        $activities = [
            [
                'name' => 'Sync Variant Rating From Category Weight',
                'model_name' => SyncVariantRatingFromCategoryActivity::class,
            ],
            [
                'name' => 'Sync Product Variants Rating From Category Weight',
                'model_name' => SyncProductVariantsRatingFromCategoryActivity::class,
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
