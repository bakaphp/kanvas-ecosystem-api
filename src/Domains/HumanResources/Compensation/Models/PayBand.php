<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Compensation\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Kanvas\HumanResources\Models\BaseModel;
use Kanvas\HumanResources\Positions\Models\Position;
use Override;

/**
 * @property int         $id
 * @property string      $uuid
 * @property int         $apps_id
 * @property int         $companies_id
 * @property int|null    $position_id
 * @property string|null $name
 * @property string|null $level
 * @property string      $currency
 * @property string      $pay_frequency
 * @property float       $min_amount
 * @property float|null  $mid_amount
 * @property float       $max_amount
 */
class PayBand extends BaseModel
{
    use UuidTrait;

    protected $table = 'hr_pay_bands';
    protected $guarded = [];

    protected $casts = [
        'is_deleted' => 'boolean',
        'min_amount' => 'float',
        'mid_amount' => 'float',
        'max_amount' => 'float',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    #[Override]
    public function getTable()
    {
        $databaseName = DB::connection($this->connection)->getDatabaseName();

        return $databaseName . '.hr_pay_bands';
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'position_id');
    }
}
