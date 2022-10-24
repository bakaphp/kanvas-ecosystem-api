<?php
declare(strict_types=1);
namespace Kanvas\AccessControlList\Repositories;

use Kanvas\AccessControlList\Models\Role;
use Kanvas\Apps\Models\Apps;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class RolesRepository
{
    /**
     * getAllRoles
     *
     * @return ?Collection
     */
    public static function getAllRoles(): ?Collection
    {
        return Role::whereNull('scope')
            ->orWhere('scope', self::getScope())
            ->get();
    }

    /**
     * getScope
     *
     * @return string
     */
    public static function getScope(?Model $user = null): string
    {
        $app = app(Apps::class);
        $user = $user ?? auth()->user();
        return "app_{$app->id}_company_{$user->default_company}";
    }
}
