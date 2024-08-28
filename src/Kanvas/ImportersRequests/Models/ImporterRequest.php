<?php
declare(strict_types=1);

namespace Kanvas\ImportersRequests\Models;

use Kanvas\Models\BaseModel;
use Baka\Traits\UuidTrait;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Companies\Models\CompaniesBranches;

/**
 * Class ImporterRequest.
 *
 * @package Kanvas\ImportersRequests\Models
 * @property string $uuid
 * @property string $app_id
 * @property string $companies_id
 * @property string $companies_branches_id
 * @property string $users_id
 * @property string $regions_id
 * @property string $data
 * @property string $status
 * @property string $created_at
 * @property string $updated_at
 * @property string $deleted_at
 */
class ImporterRequest extends BaseModel
{
    use UuidTrait;

    protected $table = 'importers_requests';

    protected $guarded = [];
    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }

    public function region()
    {
        return $this->belongsTo(Regions::class, 'regions_id', 'id');
    }

    public function branches()
    {
        return $this->belongsTo(CompaniesBranches::class, 'companies_branches_id', 'id');
    }

}
