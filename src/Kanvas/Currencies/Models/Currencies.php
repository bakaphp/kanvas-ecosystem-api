<?php

declare(strict_types=1);

namespace Kanvas\Currencies\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Companies\Models\Companies;
use Kanvas\Currencies\Factories\CurrenciesFactory;
use Kanvas\Models\BaseModel;
use Override;

/**
 * Companies Model.
 *
 * @property string $country
 * @property string $currency
 * @property string $code
 * @property string $symbol
 */
class Currencies extends BaseModel
{
    use Cachable;

    protected $table = 'currencies';

    #[Override]
    protected static function newFactory()
    {
        return CurrenciesFactory::new();
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Companies::class, 'currency_id');
    }

    public static function getByCode(string $code): self
    {
        return self::where('code', $code)->firstOrFail();
    }

    /**
     * Base currency for kanvas is USD.
     */
    public static function getBaseCurrency(): self
    {
        return self::where('code', 'USD')->firstOrFail();
    }
}
