<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\ESim;

use Baka\Traits\KanvasJobsTrait;
use Exception;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Airalo\Services\AiraloService;
use Kanvas\Connectors\CMLink\Services\CustomerService;
use Kanvas\Connectors\ESim\Enums\ConfigurationEnum;
use Kanvas\Connectors\ESim\Enums\ProviderEnum;
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

        Order::disableSearchSyncing();

        $company = Companies::getById((int) $this->argument('company_id'));

        $orders = Order::fromApp($app)->fromCompany($company)->notDeleted()->whereNotFulfilled()->orderBy('id', 'desc')->get();

        $eSimService = new ESimService($app);
        $cmLinkCustomerService = new CustomerService($app, $company);
        $airaloService = new AiraloService($app);

        foreach ($orders as $order) {
            $iccid = $order->metadata['data']['iccid'] ?? null;
            $bundle = $order->metadata['data']['plan'] ?? null;
            $qr = $order->metadata['data']['qr_code'] ?? null;
            $startDate = $order->metadata['data']['start_date'] ?? null;

            $cancelCounter = $order->get('cancel_counter', 0);
            if ($cancelCounter < 3) {
                $cancelCounter++;
                $order->set('cancel_counter', $cancelCounter);
            }

            if ($iccid == null) {
                $this->info("Order ID: {$order->id} does not have an ICCID. Check count: {$cancelCounter}");
                if ($cancelCounter >= 3) {
                    $this->info("Order ID: {$order->id} checked 3 times without ICCID. Cancelling.");
                    $order->cancel();
                    $order->fulfillCancelled();
                }

                continue;
            }

            $item = $order->items()->first();
            $variant = $item->variant;
            $provider = $variant?->getAttributeBySlug(ConfigurationEnum::VARIANT_PROVIDER_SLUG->value) ?? $variant?->product?->getAttributeBySlug(ConfigurationEnum::PROVIDER_SLUG->value);

            if ($provider == null) {
                $this->info("Order ID: {$order->id} does not have a provider.");

                continue;
            }

            match (strtolower($provider->value)) {
                strtolower(ProviderEnum::E_SIM_GO->value)        => $this->esimGoFulfillment($eSimService, $order, $iccid, $bundle),
                strtolower(ProviderEnum::EASY_ACTIVATION->value) => [],
                strtolower(ProviderEnum::CMLINK->value)          => $this->cmLinkFulfillment($cmLinkCustomerService, $order, $iccid),
                strtolower(ProviderEnum::AIRALO->value)          => $this->airaloFulfillment($airaloService, $order, $iccid, $bundle),
                default                                          => [],
            };
        }

    }

    protected function cmLinkFulfillment(CustomerService $customerService, Order $order, string $iccid): void
    {
        try {
            $response = $customerService->getEsimInfo($iccid);
        } catch (Exception $e) {
            report($e);
            $this->info("Order ID: {$order->id} does not have an ICCID.");

            $cancelCounter = $order->get('cancel_counter', 0);
            if ($cancelCounter < 3) {
                $cancelCounter++;
                $order->set('cancel_counter', $cancelCounter);
            }

            $this->info("Order ID: {$order->id} check count: {$cancelCounter}");
            if ($cancelCounter >= 3) {
                $this->warn("Order ID: {$order->id} has been checked 3 times without success. Cancelling.");
                $order->cancel();
                $order->fulfillCancelled();
            }

            return;
        }

        if (!empty($response)) {
            $order->fulfill();
            $order->completed();
            $this->info("Syncing order ID: {$order->id}");
        }
    }

    protected function esimGoFulfillment(ESimService $eSimService, Order $order, string $iccid, string $bundle): void
    {
        try {
            $response = $eSimService->getAppliedBundleStatus($iccid, $bundle);
        } catch (Exception $e) {
            report($e);
            $this->info("Order ID: {$order->id} does not have an ICCID.");

            $cancelCounter = $order->get('cancel_counter', 0);
            if ($cancelCounter < 3) {
                $cancelCounter++;
                $order->set('cancel_counter', $cancelCounter);
            }

            $this->info("Order ID: {$order->id} check count: {$cancelCounter}");
            if ($cancelCounter >= 3) {
                $this->info("Order ID: {$order->id} checked 3 times without success. Cancelling.");
                $order->cancel();
                $order->fulfillCancelled();
            }

            return;
        }

        if (!empty($response)) {
            if (isset($response['bundleState']) && $response['bundleState'] === 'active') {
                $order->fulfill();
                $order->completed();
                $this->info("Syncing order ID: {$order->id}");
            } else {
                $this->info("Order ID: {$order->id} is not active.");
            }
        }
    }

    protected function airaloFulfillment(AiraloService $airaloService, Order $order, string $iccid, string $bundle): void
    {
        try {
            $response = $airaloService->getEsimStatus($iccid, $bundle);
        } catch (Exception $e) {
            report($e);
            $this->info("Order ID: {$order->id} does not have a valid ICCID or encountered an error.");

            $cancelCounter = $order->get('cancel_counter', 0);
            if ($cancelCounter < 3) {
                $cancelCounter++;
                $order->set('cancel_counter', $cancelCounter);
            }

            $this->info("Order ID: {$order->id} check count: {$cancelCounter}");
            if ($cancelCounter >= 3) {
                $this->info("Order ID: {$order->id} checked 3 times without success. Cancelling.");
                $order->cancel();
                $order->fulfillCancelled();
            }

            return;
        }

        if (!empty($response)) {
            if (isset($response['status']) && $response['status'] === 'active') {
                $order->fulfill();
                $order->completed();
                $this->info("Syncing order ID: {$order->id}");
            } else {
                $this->info("Order ID: {$order->id} is not active. Status: ".($response['status'] ?? 'unknown'));
            }
        }
    }
}
