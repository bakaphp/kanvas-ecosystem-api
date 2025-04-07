<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\ScrapperApi\Actions\ScrapperAction;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Users\Models\Users;

class IndexProductJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Apps $app,
        public CompaniesBranches $branch,
        public Regions $region,
        public Users $user,
        public int $limit = 2000
    ) {
    }

    public function handle()
    {
        $products = Products::where('apps_id', $this->app->getId())
            ->orderBy('id', 'desc')
            ->limit($this->limit)
            ->get();
        foreach ($products as $product) {
            $action = new ScrapperAction(
                $this->app,
                $this->user,
                $this->branch,
                $this->region,
                $product->variants()->first()->slug
            );
            $action->execute();
        }
    }
}
