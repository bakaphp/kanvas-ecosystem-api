<?php

declare(strict_types=1);

namespace Kanvas\Social\Follows\Models;

use Kanvas\Social\Models\BaseModel;

class UsersFollows extends BaseModel
{
    protected $guarded = [];
    protected $table = 'users_follows';
}
