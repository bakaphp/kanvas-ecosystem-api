<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Models;

use Kanvas\Models\BaseModel;

class RoleType extends BaseModel
{
    public $table = 'roles_types';
    public $connection = 'mysql';
}
