<?php

declare(strict_types=1);

namespace Kanvas\Companies\Actions;

use Kanvas\Companies\Models\Companies;

class CompaniesTotalBranchesAction
{
    public function __construct(
        public Companies $company
    ) {
    }

    public function execute(): int
    {
        $count = $this->company->branches->count();
        $this->company->set('total_branches', $count);

        return $count;
    }
}
