<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Models;

use Baka\Casts\Json;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
}
