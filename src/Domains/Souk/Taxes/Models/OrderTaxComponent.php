<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\NoCompanyRelationshipTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Souk\Models\BaseModel;

class OrderTaxComponent extends BaseModel
{
    use NoAppRelationshipTrait;
    use NoCompanyRelationshipTrait;
    use UuidTrait;

    protected $table = 'order_tax_components';
    protected $guarded = [];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(OrderTaxSnapshot::class, 'snapshot_id', 'id');
    }
}
