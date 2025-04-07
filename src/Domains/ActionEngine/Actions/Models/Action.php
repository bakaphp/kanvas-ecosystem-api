<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Actions\Models;

use Baka\Contracts\CompanyInterface;
use Baka\Enums\StateEnums;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Kanvas\ActionEngine\Models\BaseModel;
use Nevadskiy\Tree\AsTree;

/**
 * Class Action.
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int $users_id
 * @property int $pipelines_id
 * @property int $parent_id
 * @property string path
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
    use AsTree;
    use SlugTrait;

    protected $table = 'actions';
    protected $guarded = [];

    public static function getBySlug(string $slug, CompanyInterface $company): ?self
    {
        return static::where('slug', $slug)
        ->where('is_deleted', StateEnums::NO->getValue())
        ->first();
    }
}
