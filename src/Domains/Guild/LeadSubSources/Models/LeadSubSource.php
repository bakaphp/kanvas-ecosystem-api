<?php

declare(strict_types=1);

namespace Kanvas\Guild\LeadSubSources\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Guild\Leads\Models\LeadSource;
use Kanvas\Guild\Models\BaseModel;

/**
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $leads_sources_id
 * @property string $name
 * @property string $created_at
 * @property string $updated_at
 * @property bool $is_deleted
 */
class LeadSubSource extends BaseModel
{
    use UuidTrait;

    protected $table = 'leads_sub_sources';

    protected $guarded = [];

    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'leads_sources_id', 'id');
    }
}
