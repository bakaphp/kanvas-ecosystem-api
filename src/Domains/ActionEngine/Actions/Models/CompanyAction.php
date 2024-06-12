<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Actions\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\ActionEngine\Models\BaseModel;
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
}
