<?php

namespace Domains\ActionEngine\Actions\Models;

use Baka\Traits\UuidTrait;
use Kanvas\ActionEngine\Models\BaseModel;

/**
 * Class Action.
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int $users_id
 * @property int $pipelines_id
 * @property string $name
 * @property string $slug
 * @property string $description
 * @property string $icon
 * @property string $form_fields
 * @property string $form_config
 * @property int is_active
 * @property int collects_info
 * @property int is_published
 */
class Action extends BaseModel
{
    use UuidTrait;

    protected $table = 'actions';
    protected $guarded = [];
}
