<?php

declare(strict_types=1);

namespace Kanvas\Apps\Models;

use Baka\Traits\HasCompositePrimaryKeyTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Models\BaseModel;

/**
 * AppsSettings Class.
 *
 * @property int $apps_id
 * @property string $name
 * @property string $value
 */

class Settings extends BaseModel
{
    use HasCompositePrimaryKeyTrait;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'apps_settings';

    protected $primaryKey = ['apps_id', 'name'];

    /**
     * Apps relationship.
     *
     * @return Apps
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }
}
