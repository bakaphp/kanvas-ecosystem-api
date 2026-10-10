<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Stripe;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Actions\RegisterStripePaymentLinkWebhookAction;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;

class RegisterStripePaymentLinkWebhookCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:stripe-register-payment-link-webhook
        {app_id : Kanvas app id}
        {company_id? : A company with its own Stripe key gets its own endpoint; on the app key it only anchors the shared receiver (required when the app has no main company)}';

    protected $description = 'Register the Stripe webhook that marks get-deposit engagements submitted when their payment link is paid.';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $companyId = $this->argument('company_id');
        $company = $companyId !== null ? Companies::getById((int) $companyId) : null;

        $receiver = new RegisterStripePaymentLinkWebhookAction($app, $company)->execute();

        $this->info('Receiver URL: ' . $receiver->getUrl());
        $this->info('Stripe endpoint: ' . $receiver->configuration[ConfigurationEnum::STRIPE_WEBHOOK_ENDPOINT_ID->value]);
        $this->info($receiver->configuration[ConfigurationEnum::STRIPE_SHARED_APP_ACCOUNT->value]
            ? 'Shared app-level Stripe account: serves every company on the app key.'
            : 'Company Stripe account.');

        return self::SUCCESS;
    }
}
