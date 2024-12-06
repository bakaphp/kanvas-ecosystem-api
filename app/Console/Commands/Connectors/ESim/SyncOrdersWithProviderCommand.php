<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\ESim;

use Baka\Traits\KanvasJobsTrait;
use Exception;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\ESimGo\Services\ESimService;
use Kanvas\Souk\Orders\Models\Order;

class SyncOrdersWithProviderCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:esim-connector-sync-orders {app_id} {company_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Sync orders with providers';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'));

        $orders = Order::fromApp($app)->fromCompany($company)->notDeleted()->whereNotFulfilled()->orderBy('id', 'desc')->get();

        $eSimService = new ESimService($app);

        foreach ($orders as $order) {
            $iccid = $order->metadata['data']['iccid'] ?? null;
            $bundle = $order->metadata['data']['plan'] ?? null;

            if ($iccid == null) {
                $this->info("Order ID: {$order->id} does not have an ICCID.");
                $order->cancel();
                $order->fulfillCancelled();

                continue;
            }

            #$api = $apiUrl . "/{$iccid}/esims_1GB_7D_IT_V2";
            //$api = $apiUrl . "/esims/{$iccid}/bundles/" . $bundle;

            try {
                $response = $eSimService->getAppliedBundleStatus($iccid, $bundle);
            } catch (Exception $e) {
                $this->info("Order ID: {$order->id} does not have an ICCID.");
                $order->cancel();
                $order->fulfillCancelled();

                continue;
            }

            if (! empty($response)) {
                if (isset($response['bundleState']) && $response['bundleState'] === 'active') {
                    $order->fulfill();
                    $order->completed();
                    $this->info("Syncing order ID: {$order->id}");
                } else {
                    $this->info("Order ID: {$order->id} is not active.");
                }
            }
        }

        return;
    }
}
