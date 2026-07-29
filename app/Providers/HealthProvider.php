<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Kanvas\Health\Checks\QueueSizeCheck;
use Override;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
use Spatie\Health\Facades\Health;

class HealthProvider extends ServiceProvider
{
    #[Override]
    public function register()
    {
        Health::checks([
           DatabaseCheck::new()->name('ecosystem'),
           DatabaseCheck::new()->name('inventory')->connectionName('inventory'),
           DatabaseCheck::new()->name('social')->connectionName('social'),
           DatabaseCheck::new()->name('crm')->connectionName('crm'),
           DatabaseCheck::new()->name('content_engine')->connectionName('content_engine'),
           DatabaseCheck::new()->name('workflow')->connectionName('workflow'),
           RedisCheck::new()->name('redis'),
           QueueSizeCheck::new()
               ->name('queue-sizes')
               ->thresholds([
                   'default' => 5000,
                   'scout' => 10000,
                   'agent-runtime' => 2000,
                   'batch-logger' => 5000,
                   'ledger' => 5000,
               ])
               ->warnings([
                   'default' => 1000,
                   'scout' => 100000,
                   'agent-runtime' => 500,
                   'batch-logger' => 1000,
                   'ledger' => 1000,
               ]),
        ]);
    }
}
