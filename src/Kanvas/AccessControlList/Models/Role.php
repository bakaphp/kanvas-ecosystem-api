<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Models;

use Illuminate\Support\Facades\Redis;
use Kanvas\AccessControlList\Enums\RolesEnums;
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

        return (int)$count;
    }

    public function getAbilitiesCountAttribute(): int
    {
        $count = Redis::get('role:' . $this->id . ':abilities_count');
        if (! $count) {
            $count = $this->abilities()->count();
            Redis::setex('role:' . $this->id . ':abilities_count', 120, $count);
        }

        return (int)$count;
    }

    public function isAdmin(): bool
    {
        return $this->name === RolesEnums::ADMIN->value;
    }

    public function isOwner(): bool
    {
        return $this->name === RolesEnums::OWNER->value;
    }

    public function isSystemRole(): bool
    {
        return RolesEnums::isEnumValue($this->name);
    }
}
