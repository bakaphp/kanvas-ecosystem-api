<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Guild\Models\BaseModel;

/**
 * Class LeadSource.
 *
 * @property int $id
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
class LeadSource extends BaseModel
{
    protected $table = 'leads_sources';
    protected $guarded = [];

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeadType::class, 'leads_types_id', 'id');
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }
}
