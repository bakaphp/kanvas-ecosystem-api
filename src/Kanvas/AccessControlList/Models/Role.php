<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Redis;
use Kanvas\Users\Models\Users;
use Laravel\Scout\Searchable;
use Silber\Bouncer\Database\Role as SilberRole;

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

    public function getUserCountAttribute(): int
    {
        $count = Redis::get('role:' . $this->id . ':users_count');
        if (! $count) {
            $count = $this->users()->count();
            Redis::setex('role:' . $this->id . ':users_count', 120, $count);
        }

        return $count;
    }

    public function getAbilitiesCountAttribute(): int
    {
        $count = Redis::get('role:' . $this->id . ':abilities_count');
        if (! $count) {
            $count = $this->abilities()->count();
            Redis::setex('role:' . $this->id . ':abilities_count', 120, $count);
        }

        return $count;
    }
}
