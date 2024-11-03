<?php

declare(strict_types=1);

namespace App\Console\Commands\Workflows;

use Illuminate\Console\Command;
use Kanvas\Connectors\Apollo\Workflows\Activities\ScreeningPeopleActivity;
use Kanvas\Connectors\Google\Activities\SyncMessageToDocumentActivity;
use Kanvas\Connectors\Google\Activities\SyncUserInteractionToEventActivity;
use Kanvas\Connectors\Internal\Activities\ExtractCompanyNameFromPeopleEmailActivity;
use Kanvas\Connectors\Internal\Activities\GenerateCompanyDashboardActivity;
use Kanvas\Connectors\Internal\Activities\GenerateMessageSlugActivity;
use Kanvas\Connectors\Internal\Activities\UnPublishExpiredProductActivity;
use Kanvas\Connectors\Internal\Activities\UserCustomFieldActivity;
use Kanvas\Connectors\NetSuite\Workflow\SyncCompanyWithNetSuiteActivity;
use Kanvas\Connectors\NetSuite\Workflow\SyncPeopleWithNetSuiteActivity;
use Kanvas\Connectors\RainForest\Workflows\Activities\ImportProductActivity;
use Kanvas\Connectors\ScrapperApi\Workflows\Activities\ScrapperSearchActivity;
use Kanvas\Connectors\Shopify\Jobs\ProcessShopifyInventoryLevelWebhookJob;
use Kanvas\Connectors\Shopify\Jobs\ProcessShopifyOrderWebhookJob;
use Kanvas\Connectors\Shopify\Jobs\ProcessShopifyProductWebhookJob;
use Kanvas\Connectors\Shopify\Workflows\Activities\SyncProductWithShopifyActivity;
use Kanvas\Connectors\Shopify\Workflows\Activities\SyncProductWithShopifyWithIntegrationActivity;
use Kanvas\Connectors\Stripe\Jobs\UpdatePeopleStripeSubscriptionJob;
use Kanvas\Connectors\Stripe\Workflows\Activities\SetPlanWithoutPaymentActivity;
use Kanvas\Guild\Leads\Jobs\CreateLeadsFromReceiverJob;
use Kanvas\Social\Messages\Jobs\CreateMessageFromReceiverJob;
use Kanvas\Social\Messages\Workflows\Activities\GenerateMessageTagsActivity;
use Kanvas\Users\Workflows\Activities\AssignToDefaultCompanyActivity;
use Kanvas\Workflow\Rules\Models\Action;

class KanvasWorkflowSynActionCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:workflow-sync-actions';

    public function handle(): void
    {
        $this->info('Syncing Workflow Action...');

        $actions = [
            ProcessShopifyOrderWebhookJob::class,
            UserCustomFieldActivity::class,
            ScreeningPeopleActivity::class,
            CreateLeadsFromReceiverJob::class,
            CreateMessageFromReceiverJob::class,
            UpdatePeopleStripeSubscriptionJob::class,
            ProcessShopifyOrderWebhookJob::class,
            SyncCompanyWithNetSuiteActivity::class,
            SyncPeopleWithNetSuiteActivity::class,
            GenerateMessageSlugActivity::class,
            AssignToDefaultCompanyActivity::class,
            SyncMessageToDocumentActivity::class,
            SyncUserInteractionToEventActivity::class,
            ProcessShopifyProductWebhookJob::class,
            SetPlanWithoutPaymentActivity::class,
            GenerateCompanyDashboardActivity::class,
            SyncProductWithShopifyActivity::class,
            ImportProductActivity::class,
            GenerateMessageTagsActivity::class,
            ExtractCompanyNameFromPeopleEmailActivity::class,
            ScrapperSearchActivity::class,
            UnPublishExpiredProductActivity::class,
            ProcessShopifyInventoryLevelWebhookJob::class,
            SyncProductWithShopifyWithIntegrationActivity::class,
        ];

        $createdActions = [];

        foreach ($actions as $action) {
            $record = Action::firstOrCreate([
                'name' => class_basename($action),
                'model_name' => $action,
            ]);

            // Check if the record was newly created
            if ($record->wasRecentlyCreated) {
                $createdActions[] = $record->name;
            }
        }

        // Output the names of the newly created actions
        if (! empty($createdActions)) {
            $this->info('The following actions were created:');
            foreach ($createdActions as $actionName) {
                $this->line("- {$actionName}");
            }
        } else {
            $this->info('No new actions were created.');
        }

        $this->info('Syncing Workflow Action Done!');
    }
}
