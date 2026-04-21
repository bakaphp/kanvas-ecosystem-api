<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Models;

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
class PeopleType extends BaseModel
{
    use UuidTrait;
    use SlugTrait;

    protected $table = 'people_types';
    protected $guarded = [];

    public function peoples(): HasMany
    {
        return $this->hasMany(People::class, 'people_types_id');
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'config' => Json::class,
        ];
    }
}
