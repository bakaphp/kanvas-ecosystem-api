<?php

declare(strict_types=1);

namespace Kanvas\Guild\LeadSources\Models;

use Kanvas\Guild\Models\BaseModel;
use Kanvas\Guild\Leads\Models\LeadType;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    use UuidTrait;

    protected $table = 'leads_sources';

    protected $guarded = [];

    public function leadType(): BelongsTo
    {
        return $this->belongsTo(LeadType::class, 'leads_types_id', 'id');
    }
}
