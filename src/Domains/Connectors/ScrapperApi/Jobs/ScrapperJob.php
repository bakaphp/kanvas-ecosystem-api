<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Jobs;

use Baka\Contracts\AppInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\ScrapperApi\Actions\ScrapperProcessorAction;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Users\Models\Users;

class ScrapperJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $queue = "scrapper-queue";

    public function __construct(
        public AppInterface $app,
        public Users $user,
        public CompaniesBranches $companyBranch,
        protected Regions $region,
        public array $results,
        public ?string $uuid = null
    ) {
    }

    public function handle()
    {
        new ScrapperProcessorAction(
            $this->app,
            $this->user,
            $this->companyBranch,
            $this->region,
            $this->results,
            $this->uuid
        )->execute();
    }
}
