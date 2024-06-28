<?php

declare(strict_types=1);

namespace Kanvas\Currencies\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Kanvas\Companies\Models\Companies;
use Kanvas\Currencies\Factories\CurrenciesFactory;
use Kanvas\Models\BaseModel;

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

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'currencies';

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    protected static function newFactory()
    {
        return CurrenciesFactory::new();
    }

    /**
     * Companies relationship.
     *
     * @return hasMany[j[[j[]]]]
     */
    public function companies()
    {
        return $this->hasMany(Companies::class, 'currency_id');
    }

    public static function getByCode(string $code): self
    {
        return self::where('code', $code)->firstOrFail();
    }
}
