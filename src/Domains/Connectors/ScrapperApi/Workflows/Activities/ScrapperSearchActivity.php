<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
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
            $word = $params['search'];
            if ($this->checkRecentlySearched($app, $word)) {
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
            $this->setRecentlySearched($app, $word);

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

    protected function checkRecentlySearched(AppInterface $app, string $word): bool
    {
        $key = ConfigEnum::getWordEnum($app);
        $field = ConfigEnum::SEARCHED_FIELD->value . $word;
        if (! Redis::hexists($key, $field)) {
            return false;
        }
        $value = Redis::hget($key, $field);
        $secondsApp = $app->get(ConfigEnum::SCRAPPER_SECONDS->value);
        $seconds = Carbon::createFromFormat('Y-m-d H:i:s', $value)->floatDiffInSeconds(Carbon::now());

        return $seconds < $secondsApp;
    }

    protected function setRecentlySearched(AppInterface $app, string $word): void
    {
        $key = ConfigEnum::getWordEnum($app);
        $field = ConfigEnum::SEARCHED_FIELD->value . $word;

        Redis::hset($key, $field, Carbon::now()->format('Y-m-d H:i:s'));
    }
}
