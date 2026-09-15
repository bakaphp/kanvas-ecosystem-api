<?php

declare(strict_types=1);

namespace Tests\Baka\Search;

use Baka\Search\Activities\PushEntityToSecondaryIndexActivity;
use Baka\Search\Contracts\SecondaryIndexServiceInterface;
use Kanvas\Apps\Models\Apps;

/**
 * Test double for the Rule-engine wiring tests. DynamicRuleWorkflow instantiates the Activity by its
 * catalogued FQCN (`new $activityClass(...)`), so there's no constructor seam to inject a mock through
 * — a static property is the only way to reach across that boundary and substitute a fake service.
 */
final class RecordingPushEntityToSecondaryIndexActivity extends PushEntityToSecondaryIndexActivity
{
    public static ?SecondaryIndexServiceInterface $fakeService = null;

    protected function resolveSecondaryIndexService(string $searchEngine, Apps $app): SecondaryIndexServiceInterface
    {
        return self::$fakeService ?? parent::resolveSecondaryIndexService($searchEngine, $app);
    }
}
