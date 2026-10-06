<?php

declare(strict_types=1);

namespace Kanvas\Souk\Shipping\RateCards\Models;

use Kanvas\Souk\Models\BaseModel;
use Kanvas\Souk\Shipping\RateCards\Concerns\ScopesToCompanyApp;

class RateCardCountry extends BaseModel
{
    use ScopesToCompanyApp;

    protected $table = 'shipping_rate_card_countries';

    protected array $nervousSystemEventTypes = [];

    protected $fillable = [
        'companies_id',
        'apps_id',
        'provider',
        'country_code',
        'zone',
    ];

    public static function normalizeCode(string $countryCode): string
    {
        return strtoupper(trim($countryCode));
    }
}
