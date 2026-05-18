<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Contracts;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Laravel\Ai\Contracts\Tool;

interface KanvasToolInterface extends Tool
{
    public function withContext(Apps $app, Companies $company): static;
}
