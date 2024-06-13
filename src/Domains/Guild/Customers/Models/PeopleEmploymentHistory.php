<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Models;

use Kanvas\Guild\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * @property string $company_employer_name
 * @property null|string $company_employer_phone
 * @property null|string $company_employer_address
 * @property null|string $company_employer_phone
 * @property null|string $company_employer_email
 * @property null|string $company_employer_city
 * @property null|string $company_employer_state
 * @property null|string $company_employer_zip
 */
class PeopleEmploymentHistory extends BaseModel
{
    protected $table = 'peoples_employment_history';
    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'foreign_key', 'other_key');
    }

    public function people()
    {
        return $this->belongsTo(
            People::class,
            'peoples_id',
            'id'
        );
    }
}
