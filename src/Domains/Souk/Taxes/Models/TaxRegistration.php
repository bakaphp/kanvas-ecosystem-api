<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Kanvas\Souk\Models\BaseModel;

class TaxRegistration extends BaseModel
{
    use UuidTrait;

    protected $table = 'tax_registrations';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => Json::class,
        ];
    }
}
