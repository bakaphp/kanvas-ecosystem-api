<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RainForest\Workflows;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\RainForest\Workflows\Activities\ImportProductActivity;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Users\Models\Users;
use Workflow\ActivityStub;
use Workflow\Workflow;

class SearchWorkflow extends Workflow
{
    public function execute(AppInterface $app, Users $users, CompaniesBranches $companyBranch, Regions $region, string $search)
    {
        $result = yield ActivityStub::make(ImportProductActivity::class, $app, $users, $companyBranch, $region, $search);

        return $result;
    }
}
