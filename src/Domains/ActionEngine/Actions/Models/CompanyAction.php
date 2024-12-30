<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Actions\Models;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Enums\StateEnums;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\ActionEngine\Models\BaseModel;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Nevadskiy\Tree\AsTree;

/**
 * Class CompanyAction.
 *
 * @property int $id
 * @property string $uuid
 * @property int $actions_id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $companies_branches_id
 * @property int $users_id
 * @property int $pipelines_id
 * @property int $parent_id
 * @property string $path
 * @property string $name
 * @property string $icon
 * @property string $description
 * @property string $form_config
 * @property int $is_active
 * @property int $is_published
 * @property int $weight
 */
class CompanyAction extends BaseModel
{
    use UuidTrait;
    use AsTree;

    protected $table = 'companies_actions';
    protected $guarded = [];

    public function action(): BelongsTo
    {
        return $this->belongsTo(Action::class, 'actions_id', 'id');
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class, 'pipelines_id', 'id');
    }

    public static function getByAction(Action $action, CompanyInterface $company, AppInterface $app): self
    {
        return static::where('actions_id', $action->getId())
            ->where('companies_id', $company->getId())
            ->where('apps_id', $app->getId())
            ->where('is_deleted', StateEnums::NO->getValue())
            ->firstOrFail();
    }
}
