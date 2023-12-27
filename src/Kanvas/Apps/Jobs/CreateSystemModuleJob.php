<?php

declare(strict_types=1);

namespace Kanvas\Apps\Jobs;

use Baka\Contracts\AppInterface;
use Baka\Traits\KanvasJobsTrait;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Guild\Support\CreateSystemModule as GuildCreateSystemModule;
use Kanvas\Inventory\Support\CreateSystemModule as InventoryCreateSystemModule;
use Kanvas\Social\Support\CreateSystemModule as SocialCreateSystemModule;

class CreateSystemModuleJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use KanvasJobsTrait;

    public function __construct(
        public AppInterface $app
    ) {
    }

    public function handle()
    {
        // $this->overwriteAppService($this->app);
        // $this->overwriteAppServiceLocation($this->app->getDefaultCompany()->getDefaultBranch());

        try {
            (new SocialCreateSystemModule($this->app))->run();
            (new GuildCreateSystemModule($this->app))->run();
            (new InventoryCreateSystemModule($this->app))->run();
        } catch (\Throwable $e) {
            throw new Exception($e->getMessage());
        }
    }
}
