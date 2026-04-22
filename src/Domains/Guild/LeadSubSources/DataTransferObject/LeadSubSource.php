<?php

declare(strict_types=1);

namespace Kanvas\Guild\LeadSubSources\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Guild\Leads\Models\LeadSource;
use Spatie\LaravelData\Data;

class LeadSubSource extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly LeadSource $source,
        public readonly string $name,
    ) {
    }
}
