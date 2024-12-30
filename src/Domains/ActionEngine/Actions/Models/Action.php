<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Actions\Models;

use Baka\Contracts\AppInterface;
use Baka\Enums\StateEnums;
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

    protected $table = 'actions';
    protected $guarded = [];

    public static function getBySlug(string $slug, AppInterface $app): ?self
    {
        return static::where('slug', $slug)
            ->whereIn('apps_id', [0, $app->getId()])
            ->where('is_deleted', StateEnums::NO->getValue())
            ->first();
    }
}
