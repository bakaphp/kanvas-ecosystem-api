<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Models;

use Baka\Traits\DynamicSearchableTrait;
use Illuminate\Support\Facades\Redis;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Silber\Bouncer\Database\Role as SilberRole;

/**
 * @property int $id
 * @property string $name
 * @property string $title
 * @property string $scope
 */
class Role extends SilberRole
{
    use DynamicSearchableTrait {
        search as public traitSearch;
    }
    protected $connection = 'mysql';

    public function getUserCountAttribute(): int
    {
        $count = Redis::get('role:' . $this->id . ':users_count');
        if (! $count) {
            $count = $this->users()->count();
            Redis::set('role:' . $this->id . ':users_count', 120, $count);
        }

        return (int)$count;
    }

    public function getAbilitiesCountAttribute(): int
    {
        $count = Redis::get('role:' . $this->id . ':abilities_count');
        if (! $count) {
            $count = $this->abilities()->count();
            Redis::set('role:' . $this->id . ':abilities_count', 120, $count);
        }

        return (int)$count;
    }

    public function getModules(): array
    {
        $modules = [];
        foreach ($this->abilities as $ability) {
            $module = $ability->module;
            if (! isset($modules[$module->id])) {
                $modules[$module->id] = $module;
            }
        }

        return $modules;
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

    public function searchableAs(): string
    {
        $app = app(Apps::class);
        $customIndex = $app->get('app_custom_roles_index') ?? null;

        return config('scout.prefix') . ($customIndex ?? 'roles');
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'title' => $this->title,
            'scope' => (string) $this->scope,
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return true;
    }

    public function typesenseCollectionSchema(): array
    {
        return [
            'name' => $this->searchableAs(),
            'fields' => [
                ['name' => 'id', 'type' => 'string'],
                ['name' => 'name', 'type' => 'string'],
                ['name' => 'title', 'type' => 'string', 'optional' => true],
                ['name' => 'scope', 'type' => 'string', 'facet' => true],
            ],
        ];
    }

    public static function search($query = '', $callback = null)
    {
        $query = self::traitSearch($query, $callback)
            ->where('scope', RolesEnums::getScope(app(Apps::class), null));

        if ($query->model->isTypesense()) {
            $query->options([
                'query_by' => 'name,title',
            ]);
        }

        return $query;
    }
}
