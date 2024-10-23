<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Esim;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Orders\Models\Order;

class SyncOrdersWithProvidersCommand extends Command
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

        $orders = Order::fromApp($app)->fromCompany($company)->notDeleted()->where('status', '!=', 'completed')->get();

        $authHeaderToken = $app->get('esim_auth_header_token');
        if (empty($authHeaderToken)) {
            $this->info("No auth token found for app ID: {$app->id}");

            return;
        }

        $apiUrl = $app->get('esim_api_url');

        foreach ($orders as $order) {
            $iccid = $order->metadata['data']['iccid'] ?? null;

            if ($iccid == null) {
                $this->info("Order ID: {$order->id} does not have an ICCID.");

                continue;
            }
            $api = $apiUrl . "/{$iccid}/esims_1GB_7D_IT_V2";

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $authHeaderToken,
                'Accept' => 'application/json',
            ])->get($api);

            if ($response->successful()) {
                $data = $response->json();

                if ($data['data']['status'] === 'active') {
                    $order->fulfill();
                    $order->completed();
                } else {
                    $this->info("Order ID: {$order->id} is not active.");
                }
                $this->info("Syncing order ID: {$order->id}");
            }
        }

        return;
    }
}
