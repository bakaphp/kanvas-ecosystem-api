<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Guild\Rotations\Models\Rotation;

/**
 * Class LeadReceiver.
 *
 * @property int $id
 * @property string $uuid
 * @property int $companies_id
 * @property int|null $companies_branches_id
 * @property string $name
 * @property int $users_id
 * @property int $agents_id
 * @property int $rotations_id
 * @property int $leads_sources_id
 * @property int $leads_types_id
 * @property string $source_name
 * @property string|null $template
 * @property int $is_default
 * @property int $total_leads
 * @property int $is_default
 */
class LeadReceiver extends BaseModel
{
    use UuidTrait;
    use NoAppRelationshipTrait;

    protected $table = 'leads_receivers';
    protected $guarded = [];

    /**
     * rotation
     */
    public function rotation(): BelongsTo
    {
        return $this->belongsTo(Rotation::class, 'rotations_id');
    }
}
