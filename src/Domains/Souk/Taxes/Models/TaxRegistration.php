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

    public static function bootCompaniesIdTrait(): void
    {
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'metadata' => Json::class,
        ];
    }
}
