<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Souk\Models\BaseModel;

class TaxOperation extends BaseModel
{
    protected $table = 'tax_operations';
    protected $guarded = [];

    public static function bootAppsIdTrait(): void
    {
    }

    public static function bootCompaniesIdTrait(): void
    {
    }

    protected function casts(): array
    {
        return [
            'provider_response' => Json::class,
        ];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(OrderTaxSnapshot::class, 'snapshot_id', 'id');
    }
}
