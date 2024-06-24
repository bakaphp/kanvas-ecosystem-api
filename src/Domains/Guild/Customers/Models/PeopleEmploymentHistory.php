<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Guild\Organizations\Models\Organization;

/**
 * Class PeopleEmploymentHistory
 *
 * @property int $id
 * @property int $organizations_id
 * @property int $peoples_id
 * @property string $apps_id
 * @property string $position
 * @property null|float $income
 * @property string $start_date
 * @property null|string $end_date
 * @property int status
 * @property null|string $income_type
 */
class PeopleEmploymentHistory extends BaseModel
{
    protected $table = 'peoples_employment_history';
    protected $guarded = [];

    public function people(): BelongsTo
    {
        return $this->belongsTo(
            People::class,
            'peoples_id',
            'id'
        );
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(
            Organization::class,
            'organizations_id',
            'id'
        );
    }
}
