<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Guild\Rotations\Models\Rotation;

/**
 * Class LeadReceiver.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $apps_id
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

    protected $table = 'leads_receivers';
    protected $guarded = [];

    protected $casts = [
        'template' => Json::class
    ];

    /**
     * rotation
     */
    public function rotation(): BelongsTo
    {
        return $this->belongsTo(LeadRotation::class, 'rotations_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(CompaniesBranches::class, 'companies_branches_id');
    }
}
