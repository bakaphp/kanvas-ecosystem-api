<?php

declare(strict_types=1);

namespace Kanvas\Social\Users\Models;

use Baka\Traits\SoftDeletesTrait;
use Kanvas\Social\Models\BaseModel;

/**
 *  class BlockUser
 *  @property int $users_id
 *  @property int $blocked_users_id
 *  @property int $apps_id
 *  @property string $created_at
 *  @property bool $is_deleted
 */
class BlockUser extends BaseModel
{
    use SoftDeletesTrait;

    protected $table = 'blocked_users';
}
