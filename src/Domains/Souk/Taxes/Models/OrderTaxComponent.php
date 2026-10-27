<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Souk\Models\BaseModel;

class OrderTaxComponent extends BaseModel
{
    protected $table = 'order_tax_components';
    protected $guarded = [];

    public static function bootAppsIdTrait(): void
    {
    }

    public static function bootCompaniesIdTrait(): void
    {
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(OrderTaxSnapshot::class, 'snapshot_id', 'id');
    }
}
