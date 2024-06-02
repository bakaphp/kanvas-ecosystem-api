<?php

declare(strict_types=1);

namespace Kanvas\Users\Models;

use Kanvas\Models\BaseModel;

/**
 * @property int $id
 * @property int $apps_id
 * @property int $users_id
 * @property string $email
 * @property string $request_date
 * @property string $reason
 * @property string $created_at
 * @property string $updated_at
 */
class RequestDeletedAccount extends BaseModel
{
    
    protected $table = 'request_deleted_accounts';
    protected $guarded = [];

    public function associateUsers()
    {
        return $this->belongsTo(UsersAssociatedApps::class, 'users_id', 'users_id')
                ->where('apps_id', $this->apps_id);
    }
}
