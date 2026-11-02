<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Models;

use Baka\Casts\Json;
use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\NoCompanyRelationshipTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Souk\Models\BaseModel;

class TaxOperation extends BaseModel
{
    use NoAppRelationshipTrait;
    use NoCompanyRelationshipTrait;
    use UuidTrait;

    protected $table = 'tax_operations';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'provider_response' => Json::class,
        ];
    }

    public static function reversalIdempotencyKey(OrderTaxSnapshot $snapshot, string $refundUuid): string
    {
        return hash('sha256', 'reverse:' . $snapshot->uuid . ':' . $refundUuid);
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(OrderTaxSnapshot::class, 'snapshot_id', 'id');
    }
}
