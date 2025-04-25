<?php

declare(strict_types=1);

namespace Kanvas\Locations\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Models\BaseModel;
use Kanvas\Users\Models\Users;

/**
 * Countries Class.
 *
 * @property string $name
 * @property string $code (ISO 3166-1 alpha-2)
 * @property string $mcc
 * @property string $region
 * @property string $flag
 */
class Countries extends BaseModel
{
    use Cachable;

    protected $table = 'countries';

    protected $fillable = [
        'name',
        'code',
        'flag',
    ];

    public function cities(): HasMany
    {
        return $this->hasMany(Cities::class, 'countries_id');
    }

    public function states(): HasMany
    {
        return $this->hasMany(States::class, 'countries_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(Users::class, 'country_id');
    }

    public static function getByCode(string $code): Countries
    {
        return self::query()->where('code', strtolower($code))->firstOrFail();
    }

    public function getFlagUrl(): string
    {
        return 'https://flagcdn.com/w320/' . strtolower($this->code) . '.png';
    }
}
