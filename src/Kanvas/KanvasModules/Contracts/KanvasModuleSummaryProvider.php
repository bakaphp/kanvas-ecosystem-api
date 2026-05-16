<?php

declare(strict_types=1);

namespace Kanvas\KanvasModules\Contracts;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;

interface KanvasModuleSummaryProvider
{
    /**
     * Return the per-module summary payload for a (company, app) pair.
     * Shape is module-specific (e.g. ['leads' => 1284, 'people' => 320]).
     *
     * @return array<string, mixed>
     */
    public function summary(Companies $company, Apps $app): array;
}
