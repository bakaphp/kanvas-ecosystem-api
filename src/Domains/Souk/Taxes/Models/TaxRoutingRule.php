<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Models;

use Baka\Casts\Json;
use Baka\Traits\NoCompanyRelationshipTrait;
use Baka\Traits\UuidTrait;
use Kanvas\Souk\Models\BaseModel;

class TaxRoutingRule extends BaseModel
{
    use NoCompanyRelationshipTrait;
    use UuidTrait;

    protected $table = 'tax_routing_rules';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'category_codes' => Json::class,
        ];
    }
}
