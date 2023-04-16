<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\MeiliSearchCheck;
use Spatie\Health\Checks\Checks\OptimizedAppCheck;
use Spatie\Health\Checks\Checks\QueueCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
use Spatie\Health\Checks\Checks\RedisMemoryUsageCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Facades\Health;

class HealthProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        Health::checks([
           DatabaseCheck::new()->name('ecosystem'),
           DatabaseCheck::new()->name('inventory')->connectionName('inventory'),
           DatabaseCheck::new()->name('social')->connectionName('social'),
           DatabaseCheck::new()->name('crm')->connectionName('crm'),
           RedisCheck::new()->name('redis'),
           RedisMemoryUsageCheck::new()->failWhenAboveMb(1000),
           QueueCheck::new(),
           MeiliSearchCheck::new()->url(config('scout.meilisearch.host') . '/health'),
           ScheduleCheck::new(),
          /* OptimizedAppCheck::new()->if(app()->isProduction()), */
        ]);
    }
}
