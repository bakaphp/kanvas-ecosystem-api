<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Movipass;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EchoPay\Exceptions\EchoPayException;
use Kanvas\Connectors\Movipass\Actions\CapturePaymentAction;
use Kanvas\Souk\Orders\Models\Order;

class CapturePaymentCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:movipass-capture-payment
                            {app_id : The application ID}
                            {order_id : order to apply the payment capture}
                            {payment_id : payment to capture}
                        ';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'capture authorized payment for Movipass orders';

    public function handle(): void
    {
        $appId = $this->argument('app_id');
        $orderId = $this->argument('order_id');
        $paymentId = $this->argument('payment_id');

        $order = Order::where('apps_id', $appId)
            ->where('id', $orderId)
            ->first();

        if (! $order) {
            $this->error("No order found for ID {$orderId}");
            return;
        }

        $payment = $order->payments()->where('id', $paymentId)->first();

        if (! $payment) {
            $this->error("No payment found for ID {$paymentId} on order {$order->order_number}");
            return;
        }

        $app = Apps::getById($appId);
        $this->overwriteAppService($app);

        try {
            $result = new CapturePaymentAction(
                app: $app,
                order: $order,
                payment: $payment
            )->execute();

            if ($result['status'] === 'error') {
                $this->error("Error capturing payment for order({$order->id}) {$order->order_number} : {$result['message']}");
                return;
            }

            $this->info("Payment captured for order({$order->id}) {$order->order_number} : {$result['message']}");
        } catch (EchoPayException $e) {
            $currentMetadata['echopay_error'] = $e->getErrorBody();
            $currentMetadata['echopay_error_message'] = $e->getMessage();
            $currentMetadata['echopay_error_timestamp'] = now()->toIso8601String();
            dump($currentMetadata);
        }
    }
}
