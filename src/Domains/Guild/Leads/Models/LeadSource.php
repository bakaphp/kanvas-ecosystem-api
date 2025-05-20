<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Guild\Leads\Observers\LeadSourceObserver;
use Kanvas\Guild\Models\BaseModel;

/**
 * Class LeadSource.
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property string $name
 * @property string $description
 * @property int $is_active
 * @property int $leads_types_id
 * @property string $created_at
 * @property string $updated_at
 * @property int $is_deleted
 */
#[ObservedBy([LeadSourceObserver::class])]
class LeadSource extends BaseModel
{
    use UuidTrait;

    protected $table = 'leads_sources';
    protected $guarded = [];

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeadType::class, 'leads_types_id', 'id');
    }

    public function leadReceivers(): HasMany
    {
        return $this->hasMany(LeadReceiver::class, 'leads_sources_id');
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }
}
