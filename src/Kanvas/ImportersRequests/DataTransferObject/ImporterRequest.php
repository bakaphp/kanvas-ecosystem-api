<?php
declare(strict_types=1);

namespace Kanvas\ImportersRequests\DataTransferObject;

use Spatie\LaravelData\Data;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Users\Models\Users;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Companies\Models\Companies;

class ImporterRequest extends Data
{
    public function __construct(
        public Apps $app,
        public CompaniesBranches $branch,
        public Users $user,
        public Regions $region,
        public Companies $company,
        public array $data,
    ) {
    }
}
