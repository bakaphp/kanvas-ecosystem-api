<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Models;

use Baka\Traits\UuidTrait;
use Kanvas\Souk\Models\BaseModel;

class CustomerTaxExemption extends BaseModel
{
    use UuidTrait;

    protected $table = 'customer_tax_exemptions';
    protected $guarded = [];
}
