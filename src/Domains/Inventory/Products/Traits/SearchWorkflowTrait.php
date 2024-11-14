<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Traits;

use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\WorkflowEnum;

/**
 * Trait SearchWorkflowTrait.
 */
trait SearchWorkflowTrait
{
    public function fireSearch(
        Apps $app,
        Users $user,
        CompaniesBranches $companyBranch,
        Regions $region,
        string $search
    ) {
        $app->fireWorkflow(
            event: WorkflowEnum::SEARCH->value,
            params: [
                'app' => $app,
                'user' => $user,
                'companyBranch' => $companyBranch,
                'region' => $region,
                'search' => $search,
                'uuid' => Str::uuid(),
            ]
        );
    }
}
