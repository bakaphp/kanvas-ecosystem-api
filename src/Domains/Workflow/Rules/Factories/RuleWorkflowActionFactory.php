<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\RuleWorkflowAction;
use Throwable;

class RuleWorkflowActionFactory extends Factory
{
    protected $model = RuleWorkflowAction::class;

    public function definition()
    {
        try {
            $action = Action::getByName('Lead Zoho');
        } catch(Throwable $e) {
            $action = Action::factory()->create();
        }

        return [
           'actions_id' => $action->id,
           'system_modules_id' => SystemModulesRepository::getByModelName(Lead::class),
        ];
    }
}
