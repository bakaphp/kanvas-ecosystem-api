<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Models;

use Baka\Casts\Json;
use Baka\Traits\DatabaseSearchableTrait;
use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Leads\Observers\LeadTypeObserver;
use Kanvas\Guild\Models\BaseModel;

/**
 * Class LeadType.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $uuid
 * @property string $name
 * @property string $description
 * @property int $is_active
 * @property int $is_default
 * @property string $created_at
 * @property string $updated_at
 * @property int $is_deleted
 * @property array|null $config
 */
#[ObservedBy([LeadTypeObserver::class])]
class LeadType extends BaseModel
{
    use DatabaseSearchableTrait;
    use NoAppRelationshipTrait;
    use UuidTrait;

    protected $table = 'leads_types';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'config' => Json::class,
        ];
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'leads_types_id');
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
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
