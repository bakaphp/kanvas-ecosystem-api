<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Models;

use Baka\Casts\Json;
use Baka\Traits\DatabaseSearchableTrait;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Models\BaseModel;
use Override;

/**
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int $users_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property array|null $config
 * @property bool $is_active
 * @property bool $is_default
 * @property int $is_deleted
 */
class OrganizationType extends BaseModel
{
    use DatabaseSearchableTrait;
    use SlugTrait;
    use UuidTrait;

    protected $table = 'organization_types';
    protected $guarded = [];

    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class, 'organization_type_id');
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'config' => Json::class,
        ];
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return ! $this->isDeleted();
    }

    public static function search($query = '', $callback = null)
    {
        $query = self::traitSearch($query, $callback)->where('apps_id', app(Apps::class)->getId());
        $user = auth()->user();

        if ($user instanceof UserInterface && app()->bound(CompaniesBranches::class)) {
            $query->where('companies_id', app(CompaniesBranches::class)->company->getId());
        } elseif ($user instanceof UserInterface && ! $user->isAppOwner()) {
            $query->where('companies_id', $user->getCurrentCompany()->getId());
        }

        return $query;
    }
}
