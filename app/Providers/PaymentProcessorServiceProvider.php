<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EchoPay\Services\EchoPayService;
use Override;

class PaymentProcessorServiceProvider extends ServiceProvider
{
    #[Override]
    public function register()
    {
        $this->app->bind('payment.portal', function ($app) {
            $user = request()->user();
            $company = $user->getCurrentCompany();
            $app = $app->make(Apps::class);

            return new EchoPayService(
                $app,
                $company
            );
        });
    }
}
