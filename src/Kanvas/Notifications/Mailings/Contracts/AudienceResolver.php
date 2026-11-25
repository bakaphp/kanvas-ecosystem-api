<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Mailings\Contracts;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;

interface AudienceResolver
{
    public function resolve(Apps $app, ?Companies $company, array $criteria): array;
}
