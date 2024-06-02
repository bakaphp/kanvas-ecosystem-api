<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Models\BaseModel;

/**
 * Class LeadAttempt.
 *
 * @property int $id
 * @property int $companies_id
 * @property int|null $apps_id
 * @property int|null $leads_id
 * @property string $header
 * @property string $request
 * @property string $ip
 * @property string $source
 * @property string $public_key
 * @property int $processed
 * @property string $created_at
 * @property string $updated_at
 * @property int $is_deleted
 */
class LeadAttempt extends BaseModel
{
    protected $table = 'leads_attempt';
    protected $guarded = [];
    protected $casts = [
        'request' => 'array',
        'header' => 'array',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'leads_id', 'id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id', 'id');
    }

    public function hasLead(): bool
    {
        return $this->leads_id !== null;
    }
}
