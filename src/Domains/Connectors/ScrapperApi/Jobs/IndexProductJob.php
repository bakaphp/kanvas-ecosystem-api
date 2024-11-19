<?php
declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Jobs;

use Kanvas\Apps\Models\Apps;
use Baka\Traits\KanvasJobsTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Event\Support\Setup as EventSetup;
use Kanvas\Guild\Support\Setup as GuildSetup;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Users\Models\Users;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Connectors\ScrapperApi\Actions\ScrapperAction;

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
            echo "Product {$product->name} has been reindexed\n";
            sleep(5);
        }

    }
}
