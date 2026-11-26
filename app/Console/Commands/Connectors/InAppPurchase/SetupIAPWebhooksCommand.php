<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\InAppPurchase;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\InAppPurchase\Enums\StoreProviderEnum;
use Kanvas\Connectors\InAppPurchase\Jobs\ProcessAppleSubscriptionWebhookJob;
use Kanvas\Connectors\InAppPurchase\Jobs\ProcessGoogleSubscriptionWebhookJob;
use Kanvas\Connectors\InAppPurchase\Jobs\ProcessRevenueCatWebhookJob;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Rules\Models\Action;

class SetupIAPWebhooksCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:iap-webhook:setup
                            {app_id : The application ID}
                            {company_id : Company ID}
                            {user_id : User ID}
                            {--provider=* : Only these providers (apple, google_play, revenuecat); all when omitted}
                            {--rotate-revenuecat-secret : Replace the Authorization secret of the RevenueCat receiver}';

    protected $description = 'Set up the Apple, Google Play and RevenueCat subscription webhook receivers for an app';

    public function handle(): void
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'));
        $user = UsersRepository::getUserOfCompanyById($company, (int) $this->argument('user_id'));

        $requestedProviders = (array) $this->option('provider');
        $providers = $requestedProviders === []
            ? StoreProviderEnum::cases()
            : array_map(StoreProviderEnum::from(...), $requestedProviders);

        $receivers = [
            StoreProviderEnum::APPLE->value => [
                'name' => 'Apple App Store Subscription Webhook',
                'description' => 'Receives Apple App Store Server Notifications v2 for subscription lifecycle events',
                'job_class' => ProcessAppleSubscriptionWebhookJob::class,
            ],
            StoreProviderEnum::GOOGLE_PLAY->value => [
                'name' => 'Google Play Subscription Webhook',
                'description' => 'Receives Google Play RTDN for subscription lifecycle events',
                'job_class' => ProcessGoogleSubscriptionWebhookJob::class,
            ],
            StoreProviderEnum::REVENUECAT->value => [
                'name' => 'RevenueCat Subscription Webhook',
                'description' => 'Receives RevenueCat webhook events for subscription lifecycle events',
                'job_class' => ProcessRevenueCatWebhookJob::class,
            ],
        ];

        foreach ($providers as $provider) {
            $receiverConfig = $receivers[$provider->value];
            $action = Action::firstOrCreate(
                ['model_name' => $receiverConfig['job_class']],
                ['name' => class_basename($receiverConfig['job_class'])]
            );

            $receiver = ReceiverWebhook::firstOrCreate(
                [
                    'apps_id' => $app->getId(),
                    'companies_id' => $company->getId(),
                    'action_id' => $action->getId(),
                    'name' => $receiverConfig['name'],
                    'is_deleted' => 0,
                ],
                [
                    'users_id' => $user->getId(),
                    'description' => $receiverConfig['description'],
                    'is_active' => true,
                    'configuration' => ['provider' => $provider->value],
                ]
            );

            $this->info("{$receiverConfig['name']}:");
            $this->info("  Webhook URL: {$receiver->getUrl()}");
            $this->info("  UUID: {$receiver->uuid}");

            if ($provider === StoreProviderEnum::REVENUECAT) {
                $this->setUpRevenueCatSecret($receiver);
            }
            $this->newLine();
        }

        $this->info('IAP webhook setup completed successfully.');
        $this->info('Register these URLs with App Store Connect, Google Cloud Pub/Sub and the RevenueCat dashboard.');
    }

    /**
     * The secret is printed only when it is generated: it is the whole of the receiver's authentication.
     */
    private function setUpRevenueCatSecret(ReceiverWebhook $receiver): void
    {
        $configuration = $receiver->configuration ?? [];
        $hasSecret = ! empty($configuration[ProcessRevenueCatWebhookJob::AUTHORIZATION_CONFIG_KEY]);

        if ($hasSecret && ! $this->option('rotate-revenuecat-secret')) {
            $this->info('  Authorization header: already set (pass --rotate-revenuecat-secret to replace it)');

            return;
        }

        $secret = 'Bearer ' . bin2hex(random_bytes(32));
        $configuration[ProcessRevenueCatWebhookJob::AUTHORIZATION_CONFIG_KEY] = $secret;
        $receiver->configuration = $configuration;
        $receiver->saveOrFail();

        $this->warn("  Authorization header value (shown once, paste it into RevenueCat): {$secret}");
    }
}
