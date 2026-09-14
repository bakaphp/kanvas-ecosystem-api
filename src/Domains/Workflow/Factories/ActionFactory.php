<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Shopify\Jobs\ProcessShopifyOrderWebhookJob;
use Kanvas\Workflow\Models\WorkflowAction;
use Kanvas\Workflow\Rules\Enums\ActionKindEnum;
use Override;

class ActionFactory extends Factory
{
    protected $model = WorkflowAction::class;

    public function definition()
    {
        return [
            'name' => 'ShopifyProcessOrderJob',
            'model_name' => ProcessShopifyOrderWebhookJob::class,
            'kind' => ActionKindEnum::RECEIVER->value,
        ];
    }

    /**
     * `actions` is a discovered global catalog keyed on `model_name`, not per-test data. Every
     * ReceiverWebhook built by a factory used to insert another copy of this handler, which is how
     * the table reached ~1850 rows for it. `model_name` is unique now, so a blind insert fails;
     * return the catalogued row instead.
     */
    #[Override]
    public function create($attributes = [], ?Model $parent = null)
    {
        $modelName = $attributes['model_name']
            ?? $this->getRawAttributes($parent)['model_name']
            ?? null;

        if ($modelName !== null) {
            $existing = WorkflowAction::query()->where('model_name', $modelName)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return parent::create($attributes, $parent);
    }
}
