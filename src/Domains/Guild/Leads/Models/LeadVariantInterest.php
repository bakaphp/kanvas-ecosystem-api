<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Models;

use Baka\Casts\Json;
use Baka\Traits\SoftDeletesTrait;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Guild\Leads\Observers\LeadVariantInterestObserver;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Inventory\Variants\Models\Variants;
use Override;

#[ObservedBy(LeadVariantInterestObserver::class)]
class LeadVariantInterest extends BaseModel
{
    use SoftDeletesTrait;

    public const DELETED_AT = 'is_deleted';

    protected $table = 'lead_variant_interests';
    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'price_at_interest' => 'decimal:2',
            'is_active' => 'boolean',
            'metadata' => Json::class,
            'is_deleted' => 'boolean',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'leads_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variants::class, 'variants_id');
    }
}
