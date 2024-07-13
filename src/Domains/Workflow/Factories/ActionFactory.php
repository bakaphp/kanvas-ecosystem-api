<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Connectors\Shopify\Jobs\ProcessShopifyOrderWebhookJob;
use Kanvas\Workflow\Models\WorkflowAction;

class ActionFactory extends Factory
{
    protected $model = WorkflowAction::class;

    public function definition()
    {
        return [
            'name' => 'ShopifyProcessOrderJob',
            'model_name' => ProcessShopifyOrderWebhookJob::class,
        ];
    }
}
