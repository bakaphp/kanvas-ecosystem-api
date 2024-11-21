<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RainForest\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\RainForest\Actions\ImportAction;
use Kanvas\Workflow\KanvasActivities;

class ImportProductActivity extends KanvasActivities
{
    public function execute(Model $model, AppInterface $app, array $params)
    {
        try {
            $action = new ImportAction($app, $params['user'], $params['companyBranch'], $params['region'], $params['search']);

            return $action->execute();
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ];
        }
    }
}
