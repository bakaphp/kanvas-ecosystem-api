<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Movipass;

use Baka\Traits\KanvasJobsTrait;
use Exception;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Jobs\GeneratePdfVoucherJob;
use Kanvas\Souk\Orders\Models\Order;

class GenerateOrderVoucherCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:movipass-generate-order-voucher
                            {app_id : The application ID}
                            {plate : Vehicle plate number to generate voucher for}

                        ';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Fix order items for Movipass orders within a date range';

    public function handle(): void
    {
        $appId = $this->argument('app_id');
        $plate = $this->argument('plate');


        $app = Apps::getById($appId);
        $this->overwriteAppService($app);

        $order = Order::where('apps_id', $app->getId())
            ->whereNotNull('metadata')
            ->whereRaw("JSON_VALID(metadata)")
            ->whereRaw("JSON_LENGTH(COALESCE(NULLIF(metadata, ''), '{}')) > 0")
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(COALESCE(metadata, '{}'), '$.data.vehiclePlate')) is not null")
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(COALESCE(metadata, '{}'), '$.data.vehiclePlate')) = ?", [$plate])
            ->first();

        if (! $order) {
            throw new Exception("No order found for vehicle plate {$plate}");
        }

        $vehiclePlate = $order->metadata['data']['vehiclePlate'] ?? '';
        $vehicleBrand = $order->metadata['data']['vehicleBrand'] ?? '';
        $serviceName = $order->orderType->name ?? '';
        $paymentDate = $order->metadata["data"]["payment_date"] ?? "";

        $filename = "{$order->order_number}_{$serviceName}_{$vehiclePlate}_{$vehicleBrand}";

        GeneratePdfVoucherJob::dispatchSync(
            $order,
            $order->user,
            'order-release-voucher',
            $filename,
            []
        );

        sleep(1);

        $this->info("Voucher generated for order({$order->id}) {$order->order_number} and vehicle plate {$vehiclePlate}");
    }
}
