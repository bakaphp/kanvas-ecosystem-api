<?php

declare(strict_types=1);

namespace Kanvas\Guild\LeadSources\Models;

use Baka\Traits\DatabaseSearchableTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\Models\BaseModel;

/**
 *  Class LeadSource
 *
 *  @property int $id
 *  @property int $apps_id
 *  @property int $companies_id
 *  @property string $name
 *  @property string $description
 *  @property bool $is_active
 *  @property int $leads_types_id
 *  @property datetime $created_at
 *  @property datetime $updated_at
 *  @property bool $is_deleted
 */
class LeadSource extends BaseModel
{
    use DatabaseSearchableTrait;
    use UuidTrait;

    protected $table = 'leads_sources';

    protected $guarded = [];

    public function leadType(): BelongsTo
    {
        return $this->belongsTo(LeadType::class, 'leads_types_id', 'id');
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
