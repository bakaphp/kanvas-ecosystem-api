<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Attributes\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Models\BaseModel;

/**
 * Class Attributes.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string uuid
 * @property string $name
 */
class Attributes extends BaseModel
{
    use UuidTrait;
    public $table = 'attributes';
    public $guarded = [];

    /**
     * companies.
     *
     * @return BelongsTo
     */
    public function companies() : BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    /**
     * apps.
     *
     * @return BelongsTo
     */
    public function apps() : BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }

    /**
     * Get the user's first name.
     *
     * @return Attribute
     */
    protected function value() : Attribute
    {
        return Attribute::make(
            get: fn () => $this->pivot->value,
        );
    }
}
