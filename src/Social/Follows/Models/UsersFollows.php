<?php

declare(strict_types=1);

namespace Kanvas\Social\Follows\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Social\Models\BaseModel;
use Kanvas\Users\Models\Users;

class UsersFollows extends BaseModel
{
    protected $guarded = [];
    protected $table = 'users_follows';

    /**
     * user
     */
    public function user(): BelongsTo
    {
        return $this->setConnection('ecosystem')->belongsTo(Users::class, 'users_id', 'id');
    }

    /**
     * entity
     */
    public function entity(): BelongsTo
    {
        return $this->setConnection('ecosystem')->belongsTo($this->entity_namespace, 'entity_id', 'id');
    }
}
