<?php

declare(strict_types=1);

namespace Kanvas\Scribe\FxRates\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * Daily FX rate cache.
 *
 * Per-app (not per-company) because tenants on the same app share a rate table.
 *
 * @property int $id
 * @property int $apps_id
 * @property string $base_currency
 * @property string $quote_currency
 * @property float $rate
 * @property \Illuminate\Support\Carbon $rate_date
 * @property string $source
 * @property array|null $metadata
 */
class FxRate extends EloquentModel
{
    protected $connection = 'accounting';
    protected $table = 'fx_rates';
    protected $guarded = [];

    protected $casts = [
        'rate' => 'float',
        'rate_date' => 'date',
        'metadata' => Json::class,
    ];
}
