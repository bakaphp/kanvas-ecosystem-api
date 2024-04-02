<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Models;

use Silber\Bouncer\Database\Role as SilberRole;
use Laravel\Scout\Searchable;

/**
 * @property int $id
 * @property string $name
 * @property string $title
 * @property string $scope
 */
class Role extends SilberRole
{
    use Searchable;
    protected $connection = 'mysql';
}
