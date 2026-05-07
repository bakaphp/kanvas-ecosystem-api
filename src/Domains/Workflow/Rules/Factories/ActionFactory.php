<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Connectors\Internal\Activities\GenerateCompanyDashboardActivity;
use Kanvas\Workflow\Rules\Models\Action;

class ActionFactory extends Factory
{
    protected $model = Action::class;

    public function definition()
    {
        return [
            'name' => 'Generate Company Dashboard',
            'model_name' => GenerateCompanyDashboardActivity::class,
        ];
    }
}
