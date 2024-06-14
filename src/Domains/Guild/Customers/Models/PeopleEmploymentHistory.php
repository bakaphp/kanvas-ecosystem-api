<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Guild\Models\BaseModel;

/**
 * Class PeopleEmploymentHistory
 *
 * @property int $id
 * @property int $peoples_id
 * @property string $apps_id
 * @property string $position
 * @property null|float $income
 * @property string $start_date
 * @property null|string $end_date
 * @property int status
 * @property null|string $income_type
 * @property string $company_name
 * @property null|string $company_phone
 * @property null|string $company_address
 * @property null|string $company_phone
 * @property null|string $company_email
 * @property null|string $company_city
 * @property null|string $company_state
 * @property null|string $company_zip
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
}
