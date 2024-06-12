<?php
declare(strict_types=1);

namespace Kanvas\Guild\Customers\Models;

use Kanvas\Guild\Models\BaseModel;

/**
 * Class PeopleEmploymentHistory
 *
 * @property int $id
 * @property int $peoples_id
 * @property string $company_name
 * @property string $position
 * @property string $start_date
 * @property string $end_date
 * @property string $description
 */
class PeopleEmploymentHistory extends BaseModel 
{
    protected $table = 'peoples_employment_history';
    protected $guarded = [];
}