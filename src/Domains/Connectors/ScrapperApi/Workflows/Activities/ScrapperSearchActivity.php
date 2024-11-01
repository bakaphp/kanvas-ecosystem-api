<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Redis;
use Kanvas\Connectors\ScrapperApi\Actions\ScrapperAction;
use Kanvas\Connectors\ScrapperApi\Enums\ConfigEnum;
use Laravel\Octane\Facades\Octane;

use function Sentry\captureException;

use Throwable;
use Workflow\Activity;

class ScrapperSearchActivity extends Activity
{
    public $tries = 3;
    public $queue = ConfigEnum::ACTIVITY_QUEUE->value;

    public function execute(Model $model, AppInterface $app, array $params): array
    {
        try {
            $word = ConfigEnum::getWordEnum($app, $params['search']);
            if (Redis::exists($word)) {
                return [
                    'error' => 'Already searched this word recently',
                ];
            }

            $action = new ScrapperAction(
                $app,
                $params['user'],
                $params['companyBranch'],
                $params['region'],
                $params['search']
            );

            [$results] = Octane::concurrently([
                fn () => $action->execute(),
            ]);

            $expiration = 3 * 24 * 60 * 60;

            Redis::setex($word, $expiration, true);

            return [
                'word' => $word,
            ];
        } catch (Throwable $e) {
            captureException($e);

            return [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ];
        }
    }
}
