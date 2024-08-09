<?php
declare(strict_types=1);

namespace Kanvas\ImportersTemplates\DataTransferObject;

use Spatie\LaravelData\Data;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;
use Kanvas\Companies\Models\Companies;
use Kanvas\ImportersTemplates\Models\AttributesImportersTemplates;

class ImportersTemplates extends Data
{
    public function __construct(
        public Apps $apps,
        public Users $users,
        public Companies $companies,
        public string $name,
        public ?string $description = null,
        public array $attributes
    ) {
        
    }
}
