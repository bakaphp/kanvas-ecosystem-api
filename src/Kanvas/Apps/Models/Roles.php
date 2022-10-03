<?php

declare(strict_types=1);

namespace Kanvas\Apps\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Models\BaseModel;

/**
 * AppsRoles Class.
 *
 * @property int $apps_id
 * @property string $roles_name
 */
class Roles extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'apps_roles';

    /**
     * Apps relationship.
     *
     * @return BelongsTo
     */
    public function app() : BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }
}
