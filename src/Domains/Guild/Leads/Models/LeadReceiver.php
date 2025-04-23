<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Leads\Observers\LeadReceiverObserver;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Guild\Rotations\Models\Rotation;
use Kanvas\Traits\DefaultTrait;
use Kanvas\Users\Models\Users;

/**
 * Class LeadReceiver.
 *
 * @property int         $id
 * @property string      $uuid
 * @property int|null    $apps_id
 * @property int         $companies_id
 * @property int|null    $companies_branches_id
 * @property string      $name
 * @property int         $users_id
 * @property int         $agents_id
 * @property int         $rotations_id
 * @property int         $leads_sources_id
 * @property int         $leads_types_id
 * @property string      $source_name
 * @property string|null $template
 * @property int         $is_default
 * @property int         $total_leads
 * @property int         $is_default
 */
#[ObservedBy(LeadReceiverObserver::class)]
class LeadReceiver extends BaseModel
{
    use UuidTrait;
    use DefaultTrait;

    protected $table = 'leads_receivers';
    protected $guarded = [];

    protected $casts = [
        'template' => Json::class,
    ];

    /**
     * rotation.
     */
    public function rotation(): BelongsTo
    {
        return $this->belongsTo(LeadRotation::class, 'rotations_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(CompaniesBranches::class, 'companies_branches_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'agents_id');
    }

    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'leads_sources_id');
    }

    public function leadType(): BelongsTo
    {
        return $this->belongsTo(LeadType::class, 'lead_types_id');
    }
}
