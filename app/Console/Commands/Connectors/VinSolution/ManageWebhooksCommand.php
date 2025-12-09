<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\VinSolution;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Services\EventService;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Throwable;

class ManageWebhooksCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:vinsolution-webhook 
                            {action : Action to perform (add|list)}
                            {webhook_id? : Webhook ID (required for add action)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage VinSolution webhooks - add webhook or list active webhooks';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $action = $this->argument('action');

        try {
            if ($action === 'add') {
                $this->addWebhook();
            } elseif ($action === 'list') {
                $this->listWebhooks();
            } else {
                $this->error("Invalid action: {$action}. Use: add or list");
            }
        } catch (Throwable $e) {
            $this->error('Failed to manage webhooks: ' . $e->getMessage());
        }
    }

    /**
     * Add/update a webhook with VinSolution.
     */
    private function addWebhook(): void
    {
        $webhookId = $this->argument('webhook_id');
        if (! $webhookId) {
            $this->error('Webhook ID is required for add action.');

            return;
        }

        $webhook = ReceiverWebhook::where('id', $webhookId)->notDeleted()->first();
        if (! $webhook) {
            $this->error("Webhook with ID {$webhookId} not found.");

            return;
        }

        // Get app, company, and user from the webhook
        $app = $webhook->app;
        $company = $webhook->company;
        $user = $webhook->user;

        /** @var \Baka\Contracts\AppInterface $app */
        $this->overwriteAppService($app);

        // Check if company has VinSolution configuration
        $dealerId = 0; //$company->get(ConfigurationEnum::COMPANY->value);
        if (! $dealerId) {
            //  $this->error('Company does not have VinSolution configuration');

            //return;
        }

        try {
            $eventService = new EventService($app, $company, $user);

            $this->info("Adding/updating webhook '{$webhook->name}' with VinSolution...");

            // Register the webhook subscriber - EventService expects the webhook object directly
            try {
                $result = $eventService->registerSubscriber($webhook);

                $this->info('✅ Webhook registered successfully with VinSolution!');
            } catch (Throwable $e) {
                $result = $eventService->updateSubscriber($webhook);
                $this->info('✅ Webhook updated successfully with VinSolution!');
            }

            /*   if ($result) {
                  $this->info('✅ Webhook registered successfully with VinSolution!');

                  // Register default subscriptions
                  $subscriptions = ['LeadCreated', 'LeadUpdated', 'CustomerCreated', 'CustomerUpdated'];
                  $this->info('Registering subscriptions: ' . implode(', ', $subscriptions));

                  $subscriptionResult = $eventService->updateSubscriptions((int) $dealerId, $subscriptions);

                  if ($subscriptionResult) {
                      $this->info('✅ Subscriptions registered successfully!');

                      // Update webhook configuration to track registration
                      $configuration = $webhook->configuration ?? [];
                      $configuration['vinsolution_subscriptions'] = $subscriptions;
                      $configuration['vinsolution_registered_at'] = now()->toISOString();
                      $webhook->update(['configuration' => $configuration]);
                  } else {
                      $this->warn('⚠️ Webhook registered but failed to add subscriptions.');
                  }
              } else {
                  $this->error('❌ Failed to register webhook with VinSolution');
              } */
        } catch (Throwable $e) {
            $this->error("❌ Failed to add webhook: {$e->getMessage()}");
        }
    }

    /**
     * List all active webhooks and show VinSolution status.
     */
    private function listWebhooks(): void
    {
        try {
            $webhooks = ReceiverWebhook::where('is_deleted', 0)
                ->where('is_active', 1)
                ->get();

            $this->info('📋 Active Webhooks:');
            $this->info('==================');

            if ($webhooks->isEmpty()) {
                $this->info('No active webhooks found.');

                return;
            }

            foreach ($webhooks as $webhook) {
                $this->info("ID: {$webhook->id} | Name: {$webhook->name} | Company: {$webhook->company->name}");
                $this->info("  URL: {$webhook->getUrl()}");

                if (isset($webhook->configuration['vinsolution_subscriptions'])) {
                    $this->info('  VinSolution Subscriptions: ' . implode(', ', $webhook->configuration['vinsolution_subscriptions']));
                }

                if (isset($webhook->configuration['vinsolution_registered_at'])) {
                    $this->info('  Registered: ' . (string) $webhook->configuration['vinsolution_registered_at']);
                }

                // Try to show VinSolution status for webhooks that have configuration
                $this->showVinSolutionStatus($webhook);

                $this->info('  ---');
            }

            $this->info("\nTotal active webhooks: " . $webhooks->count());
            $this->info("\nAvailable subscription types:");
            foreach (EventService::availableSubscriptions() as $subscription) {
                $this->info("  - {$subscription}");
            }
        } catch (Throwable $e) {
            $this->error("❌ Failed to list webhooks: {$e->getMessage()}");
        }
    }

    /**
     * Show VinSolution status for a webhook if it has proper configuration.
     */
    private function showVinSolutionStatus(ReceiverWebhook $webhook): void
    {
        try {
            // Check if webhook has VinSolution configuration
            $company = $webhook->company;
            $user = $webhook->user;
            $app = $webhook->app;

            $dealerId = $company->get(ConfigurationEnum::COMPANY->value);
            $vinUserId = $user->get(ConfigurationEnum::getUserKey($company, $user));

            if (! $dealerId || ! $vinUserId) {
                $this->info('  VinSolution: ❌ Not configured');

                return;
            }

            /** @var \Baka\Contracts\AppInterface $app */
            $this->overwriteAppService($app);
            $eventService = new EventService($app, $company, $user);

            $subscriber = $eventService->getSubscriber();
            $subscriptions = $eventService->getSubscriptions();

            if (! empty($subscriber)) {
                $status = $subscriber['status'] ?? 'Unknown';
                $this->info("  VinSolution: ✅ {$status}");

                if (! empty($subscriptions)) {
                    $totalSubs = 0;
                    foreach ($subscriptions as $subscription) {
                        if (isset($subscription['subscriptions'])) {
                            $totalSubs += count($subscription['subscriptions']);
                        }
                    }
                    if ($totalSubs > 0) {
                        $this->info("    Active subscriptions: {$totalSubs}");
                    }
                }
            } else {
                $this->info('  VinSolution: ⚠️  Not registered');
            }
        } catch (Throwable $e) {
            $this->info('  VinSolution: ❌ Error checking status');
        }
    }
}
