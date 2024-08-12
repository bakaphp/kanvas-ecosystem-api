<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Importer\DataTransferObjects;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Data;

class ImporterRequest extends Data
{
    public function __construct(
        public Companies $company,
        public Users $user,
        public Apps $app,
        public string $jobUuid,
        public array $request,
        public int $productsCount = 0
    ) {
    }
}
