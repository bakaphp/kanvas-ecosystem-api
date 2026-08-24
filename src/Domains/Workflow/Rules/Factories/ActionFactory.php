<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Internal\Activities\GenerateCompanyDashboardActivity;
use Kanvas\Workflow\Rules\Enums\ActionKindEnum;
use Kanvas\Workflow\Rules\Models\Action;
use Override;

class ActionFactory extends Factory
{
    protected $model = Action::class;

    public function definition()
    {
        return [
            'name' => 'Generate Company Dashboard',
            'model_name' => GenerateCompanyDashboardActivity::class,
            'kind' => ActionKindEnum::WORKFLOW->value,
        ];
    }

    /**
     * `actions` is a discovered global catalog keyed on `model_name`, not per-test data — a factory
     * that inserted blindly is how the table accumulated ~1850 rows for a single handler. `model_name`
     * is unique now, so a second insert would fail outright; return the catalogued row instead.
     */
    #[Override]
    public function create($attributes = [], ?Model $parent = null)
    {
        $modelName = $attributes['model_name']
            ?? $this->getRawAttributes($parent)['model_name']
            ?? null;

        if ($modelName !== null) {
            $existing = Action::query()->where('model_name', $modelName)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return parent::create($attributes, $parent);
    }
}
