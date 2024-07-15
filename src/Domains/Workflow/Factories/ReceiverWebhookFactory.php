<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Factories;

use Baka\Traits\KanvasFactoryStateTrait;
use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;

class ReceiverWebhookFactory extends Factory
{
    use KanvasFactoryStateTrait;

    protected $model = ReceiverWebhook::class;

    public function definition()
    {
        $action = WorkflowAction::factory()->create();
        return [
            'name' => $this->faker->name,
            'action_id' => $action->getId(),
            'configuration' => ['region_id' => 1],
        ];
    }
}
