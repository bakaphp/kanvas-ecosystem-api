<?php

declare(strict_types=1);

namespace Kanvas\Scribe\TaxCodes\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Scribe\Models\BaseModel;

/**
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $uuid
 * @property string $code
 * @property string $name
 * @property string|null $jurisdiction
 * @property bool $is_active
 * @property string $source
 * @property string|null $external_id
 * @property array|null $metadata
 * @property bool $is_deleted
 * @property int|null $users_id
 */
class TaxCode extends BaseModel
{
    use UuidTrait;

    protected $table = 'tax_codes';
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'is_deleted' => 'boolean',
        'metadata' => Json::class,
    ];

    public function rates(): HasMany
    {
        return $this->hasMany(TaxRate::class, 'tax_code_id', 'id')
            ->orderBy('sort_order');
    }
}
