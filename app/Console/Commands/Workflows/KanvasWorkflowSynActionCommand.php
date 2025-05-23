<?php

declare(strict_types=1);

namespace App\Console\Commands\Workflows;

use Illuminate\Console\Command;
use Kanvas\Apps\Activities\AppUsersNotificationByRoleActivity;
use Kanvas\Connectors\AeroAmbulancia\Workflows\Activities\CreateAeroAmbulanciaSubscriptionActivity;
use Kanvas\Connectors\Amplitude\WebhookReceivers\AmplitudeEventStreamWebhookJob;
use Kanvas\Connectors\Apollo\Workflows\Activities\ScreeningPeopleActivity;
use Kanvas\Connectors\Credit700\Workflow\CreateCreditScoreFromLeadActivity;
use Kanvas\Connectors\Credit700\Workflow\CreateCreditScoreFromMessageActivity;
use Kanvas\Connectors\ESim\WorkflowActivities\CreateOrderInESimActivity;
use Kanvas\Connectors\ESim\WorkflowActivities\UpdateOrderStripePaymentActivity;
use Kanvas\Connectors\Ghost\Jobs\CreatePeopleFromGhostReceiverJob;
use Kanvas\Connectors\Google\Activities\CovertMapsCoordinatesToImageActivity;
use Kanvas\Connectors\Google\Activities\GenerateMessageTagsWithAiActivity;
use Kanvas\Connectors\Google\Activities\GenerateUserForYouFeedActivity;
use Kanvas\Connectors\Google\Activities\SyncMessageToDocumentActivity;
use Kanvas\Connectors\Google\Activities\SyncUserInteractionToEventActivity;
use Kanvas\Connectors\InAppPurchase\Workflows\LinkMessageToOrderActivity;
use Kanvas\Connectors\Intellicheck\Activities\IdVerificationReportActivity;
use Kanvas\Connectors\Internal\Activities\CalculateWarehouseQuantityActivity;
use Kanvas\Connectors\Internal\Activities\ExtractCompanyNameFromPeopleEmailActivity;
use Kanvas\Connectors\Internal\Activities\GenerateCompanyDashboardActivity;
use Kanvas\Connectors\Internal\Activities\GenerateMessageSlugActivity;
use Kanvas\Connectors\Internal\Activities\GeneratePdfActivity;
use Kanvas\Connectors\Internal\Activities\UnPublishExpiredProductActivity;
use Kanvas\Connectors\Internal\Activities\UnPublishExpiredProductsAfterImportActivity;
use Kanvas\Connectors\Internal\Activities\UserCustomFieldActivity;
use Kanvas\Connectors\IPlus\Workflows\Activities\SyncOrderWithIPlusActivities;
use Kanvas\Connectors\IPlus\Workflows\Activities\SyncPeopleWithIPlusActivities;
use Kanvas\Connectors\Mindee\Workflows\ProcessVehicleImageActivity as WorkflowsProcessVehicleImageActivity;
use Kanvas\Connectors\NetSuite\Webhooks\ProcessNetSuiteCompanyCustomerWebhookJob;
use Kanvas\Connectors\NetSuite\Workflow\SyncCompanyWithNetSuiteActivity;
use Kanvas\Connectors\NetSuite\Workflow\SyncPeopleWithNetSuiteActivity;
use Kanvas\Connectors\OfferLogix\Workflow\SoftPullActivity;
use Kanvas\Connectors\OfferLogix\Workflow\SoftPullFromLeadActivity;
use Kanvas\Connectors\PlateRecognizer\Workflows\ProcessVehicleImageActivity;
use Kanvas\Connectors\PromptMine\Workflows\Activities\LLMMessageResponseActivity;
use Kanvas\Connectors\PromptMine\Workflows\Activities\PremiumPromptFlagActivity;
use Kanvas\Connectors\PromptMine\Workflows\Activities\PromptImageFilterActivity;
use Kanvas\Connectors\PromptMine\Workflows\Activities\SaveLlmChoiceActivity;
use Kanvas\Connectors\RainForest\Workflows\Activities\ImportProductActivity;
use Kanvas\Connectors\Recombee\Workflows\PushMessageToItemActivity;
use Kanvas\Connectors\Recombee\Workflows\PushUserInteractionToEventActivity;
use Kanvas\Connectors\SalesAssist\Activities\AttachFileToChecklistItemActivity;
use Kanvas\Connectors\SalesAssist\Activities\ProcessMessageVehicleImageActivity;
use Kanvas\Connectors\SalesAssist\Activities\PullLeadActivity;
use Kanvas\Connectors\SalesAssist\Activities\PullPeopleActivity;
use Kanvas\Connectors\ScrapperApi\Workflows\Activities\ScrapperSearchActivity;
use Kanvas\Connectors\Shopify\Jobs\ProcessShopifyInventoryLevelWebhookJob;
use Kanvas\Connectors\Shopify\Jobs\ProcessShopifyOrderWebhookJob;
use Kanvas\Connectors\Shopify\Jobs\ProcessShopifyProductWebhookJob;
use Kanvas\Connectors\Shopify\Jobs\ShopifyCompanyConfigWebhookJob;
use Kanvas\Connectors\Shopify\Jobs\ShopifyOrderNotesWebhookJob;
use Kanvas\Connectors\Shopify\Workflows\Activities\CreateShopifyDraftOrderActivity;
use Kanvas\Connectors\Shopify\Workflows\Activities\CreateUserActivity;
use Kanvas\Connectors\Shopify\Workflows\Activities\DeleteVariantFromShopifyActivity;
use Kanvas\Connectors\Shopify\Workflows\Activities\PushOrderActivity;
use Kanvas\Connectors\Shopify\Workflows\Activities\SyncProductWithShopifyActivity;
use Kanvas\Connectors\Shopify\Workflows\Activities\SyncProductWithShopifyWithIntegrationActivity;
use Kanvas\Connectors\Stripe\Jobs\ImportStripePlanWebhookJob;
use Kanvas\Connectors\Stripe\Jobs\ImportStripePriceWebhookJob;
use Kanvas\Connectors\Stripe\Jobs\UpdatePeopleStripeSubscriptionJob;
use Kanvas\Connectors\Stripe\Webhooks\CashierStripeWebhookJob;
use Kanvas\Connectors\Stripe\Webhooks\StripePaymentIntentWebhookJob;
use Kanvas\Connectors\Stripe\Workflows\Activities\GenerateStripeSignupLinkForUserActivity;
use Kanvas\Connectors\Stripe\Workflows\Activities\SetOrderPaymentIntentActivity;
use Kanvas\Connectors\Stripe\Workflows\Activities\SetPlanWithoutPaymentActivity;
use Kanvas\Connectors\VinSolution\Workflow\PullUserInformationActivity;
use Kanvas\Connectors\VinSolution\Workflow\PushCoBuyerActivity;
use Kanvas\Connectors\WaSender\Webhooks\ProcessWaSenderWebhookJob;
use Kanvas\Connectors\WaSender\Workflows\AgentChannelResponderActivity;
use Kanvas\Connectors\WooCommerce\Webhooks\SyncExternalWooCommerceUserWebhookJob;
use Kanvas\Connectors\Zoho\Jobs\SwitchZohoLeadOwnerReceiverJob;
use Kanvas\Connectors\Zoho\Jobs\SyncZohoAgentFromReceiverJob;
use Kanvas\Guild\Leads\Jobs\CreateLeadsFromReceiverJob;
use Kanvas\Social\Follows\Workflows\SendMessageNotificationToFollowersActivity;
use Kanvas\Social\Messages\Jobs\CreateMessageFromReceiverJob;
use Kanvas\Social\Messages\Workflows\Activities\DistributeMessageActivity;
use Kanvas\Social\Messages\Workflows\Activities\GenerateMessageTagsActivity;
use Kanvas\Social\Messages\Workflows\Activities\MessageOwnerChildNotificationActivity;
use Kanvas\Social\Messages\Workflows\Activities\MessageOwnerInteractionNotifierActivity;
use Kanvas\Social\Messages\Workflows\Activities\MessageReportNotificationActivity;
use Kanvas\Social\Messages\Workflows\Activities\OptimizeImageFromMessageActivity;
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
            SyncCompanyWithNetSuiteActivity::class,
            SyncPeopleWithNetSuiteActivity::class,
            GenerateMessageSlugActivity::class,
            AssignToDefaultCompanyActivity::class,
            SyncMessageToDocumentActivity::class,
            SyncUserInteractionToEventActivity::class,
            ProcessShopifyProductWebhookJob::class,
            ImportStripePlanWebhookJob::class,
            ImportStripePriceWebhookJob::class,
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
            UnPublishExpiredProductsAfterImportActivity::class,
            GenerateUserForYouFeedActivity::class,
            AppUsersNotificationByRoleActivity::class,
            ProcessNetSuiteCompanyCustomerWebhookJob::class,
            CreatePeopleFromGhostReceiverJob::class,
            CashierStripeWebhookJob::class,
            CreateOrderInESimActivity::class,
            AmplitudeEventStreamWebhookJob::class,
            CreateShopifyDraftOrderActivity::class,
            DeleteVariantFromShopifyActivity::class,
            CreateUserActivity::class,
            SyncOrderWithIPlusActivities::class,
            SyncPeopleWithIPlusActivities::class,
            SyncZohoAgentFromReceiverJob::class,
            SoftPullActivity::class,
            CreateCreditScoreFromMessageActivity::class,
            LinkMessageToOrderActivity::class,
            GenerateStripeSignupLinkForUserActivity::class,
            CreateCreditScoreFromLeadActivity::class,
            GeneratePdfActivity::class,
            SoftPullFromLeadActivity::class,
            SendMessageNotificationToFollowersActivity::class,
            MessageOwnerInteractionNotifierActivity::class,
            MessageOwnerChildNotificationActivity::class,
            SwitchZohoLeadOwnerReceiverJob::class,
            OptimizeImageFromMessageActivity::class,
            ShopifyOrderNotesWebhookJob::class,
            ShopifyCompanyConfigWebhookJob::class,
            PullUserInformationActivity::class,
            GenerateMessageTagsWithAiActivity::class,
            CreateAeroAmbulanciaSubscriptionActivity::class,
            PushUserInteractionToEventActivity::class,
            PushMessageToItemActivity::class,
            DistributeMessageActivity::class,
            CovertMapsCoordinatesToImageActivity::class,
            SaveLlmChoiceActivity::class,
            PushCoBuyerActivity::class,
            MessageReportNotificationActivity::class,
            StripePaymentIntentWebhookJob::class,
            UpdateOrderStripePaymentActivity::class,
            AttachFileToChecklistItemActivity::class,
            PromptImageFilterActivity::class,
            IdVerificationReportActivity::class,
            SyncExternalWooCommerceUserWebhookJob::class,
            PullLeadActivity::class,
            PullPeopleActivity::class,
            CalculateWarehouseQuantityActivity::class,
            PremiumPromptFlagActivity::class,
            SetOrderPaymentIntentActivity::class,
            ProcessWaSenderWebhookJob::class,
            AgentChannelResponderActivity::class,
            PushOrderActivity::class,
            ProcessVehicleImageActivity::class,
            WorkflowsProcessVehicleImageActivity::class,
            ProcessMessageVehicleImageActivity::class,
            LLMMessageResponseActivity::class,
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
