<?php
declare(strict_types=1);
namespace Kanvas\Guild\Leads\DataTransferObject;

use Spatie\LaravelData\Data;
use Kanvas\Companies\Models\Companies;
use Kanvas\Apps\Models\Apps;

class LeadRotation extends Data
{
    public function __construct(
        public Apps $app,
        public Companies $company,
        public string $name,
        public ?string $leads_rotations_email = null,
        public int $hits = 0,
        public array $agents = []
    ) {
    }
}
