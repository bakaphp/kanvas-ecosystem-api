<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\ScrapperApi\Actions\ScrapperAction;
use Kanvas\Connectors\ScrapperApi\Enums\ConfigEnum;

use function Sentry\captureException;

use Workflow\Activity;

class ScrapperSearchActivity extends Activity
{
    public $tries = 3;
    public $queue = ConfigEnum::ACTIVITY_QUEUE->value;

    public function execute(Model $model, AppInterface $app, array $params): array
    {
        try {
            $action = new ScrapperAction(
                $app,
                $params['user'],
                $params['companyBranch'],
                $params['region'],
                $params['search']
            );

            return $action->execute();
        } catch (\Throwable $e) {
            captureException($e);

            return [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ];
        }
    }
}
