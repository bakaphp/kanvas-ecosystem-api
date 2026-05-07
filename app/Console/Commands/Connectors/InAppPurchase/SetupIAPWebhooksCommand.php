<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\InAppPurchase;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\InAppPurchase\Jobs\ProcessAppleSubscriptionWebhookJob;
use Kanvas\Connectors\InAppPurchase\Jobs\ProcessGoogleSubscriptionWebhookJob;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Rules\Models\Action;

class SetupIAPWebhooksCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:iap-webhook:setup
                            {app_id : The application ID}
                            {company_id : Company ID}
                            {user_id : User ID}';

    protected $description = 'Set up Apple and Google Play subscription webhook receivers for an app';

    public function handle(): void
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'));
        $user = UsersRepository::getUserOfCompanyById($company, (int) $this->argument('user_id'));

        $receivers = [
            [
                'name' => 'Apple App Store Subscription Webhook',
                'description' => 'Receives Apple App Store Server Notifications v2 for subscription lifecycle events',
                'job_class' => ProcessAppleSubscriptionWebhookJob::class,
                'configuration' => ['provider' => 'apple'],
            ],
            [
                'name' => 'Google Play Subscription Webhook',
                'description' => 'Receives Google Play RTDN for subscription lifecycle events',
                'job_class' => ProcessGoogleSubscriptionWebhookJob::class,
                'configuration' => ['provider' => 'google_play'],
            ],
        ];

        foreach ($receivers as $receiverConfig) {
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
                    'configuration' => $receiverConfig['configuration'],
                ]
            );

            $this->info("{$receiverConfig['name']}:");
            $this->info("  Webhook URL: {$receiver->getUrl()}");
            $this->info("  UUID: {$receiver->uuid}");
            $this->newLine();
        }

        $this->info('IAP webhook setup completed successfully.');
        $this->info('Register these URLs with Apple App Store Connect and Google Cloud Pub/Sub respectively.');
    }
}
