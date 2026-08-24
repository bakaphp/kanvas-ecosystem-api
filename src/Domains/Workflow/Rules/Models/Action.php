<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Models;

use Baka\Casts\Json;
use Kanvas\Workflow\Models\BaseModel;
use Kanvas\Workflow\Rules\Factories\ActionFactory;
use Kanvas\Workflow\Traits\CatalogedByHandler;
use Override;

/**
 * A catalog entry discovered from `#[WorkflowAction]`. Everything past `name` is written for whoever
 * assembles a rule — increasingly an agent — so it can pick a step and configure it without reading
 * the handler.
 *
 * @property int $id
 * @property string $name
 * @property string $model_name
 * @property string $kind
 * @property string|null $description
 * @property string|null $integration
 * @property array|null $requires_config
 * @property array|null $params
 * @property array|null $required_params
 * @property int $is_deleted
 */
class Action extends BaseModel
{
    use CatalogedByHandler;

    protected $table = 'actions';

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'requires_config' => Json::class,
            'params' => Json::class,
            'required_params' => Json::class,
        ];
    }

    /**
     * Settings keys this step needs before it can run. Empty means it has no external dependency —
     * NOT that nobody has declared one, which the catalog cannot tell apart and deliberately reports
     * as "no requirements declared".
     *
     * @return list<string>
     */
    public function requiredConfigKeys(): array
    {
        return array_values(array_filter(
            array_map('strval', $this->requires_config ?? []),
            fn (string $key): bool => $key !== ''
        ));
    }

    /**
     * @return array<string, string>
     */
    public function paramDescriptions(): array
    {
        $params = $this->params ?? [];

        return is_array($params) ? array_map('strval', $params) : [];
    }

    /**
     * Params this step cannot run correctly without. Empty means none are declared, which is not the
     * same as "none are needed" — an undocumented step declares nothing.
     *
     * @return list<string>
     */
    public function requiredParamNames(): array
    {
        return array_values(array_filter(
            array_map('strval', $this->required_params ?? []),
            fn (string $name): bool => $name !== ''
        ));
    }

    #[Override]
    protected static function newFactory()
    {
        return ActionFactory::new();
    }
}
