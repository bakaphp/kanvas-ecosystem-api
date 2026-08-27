<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Kanvas\Companies\Groups\Observers\CompaniesGroupsObserver;
use Kanvas\Companies\Models\CompaniesGroups;
use Kanvas\Connectors\ScrapperApi\Listeners\CartListener;
use Kanvas\Guild\Customers\Listeners\UpdatePeopleMessageTimestampsListener;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Models\PeopleEmploymentHistory;
use Kanvas\Guild\Customers\Observers\PeopleEmploymentHistoryObserver;
use Kanvas\Guild\Customers\Observers\PeopleObserver;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Observers\LeadObserver;
use Kanvas\Intelligence\AgentRuntime\Events\AgentDeploymentStatusChanged;
use Kanvas\Intelligence\AgentRuntime\Listeners\SendAgentDeploymentLifecycleEmailListener;
use Kanvas\Intelligence\Agents\Events\AgentChatResponseEvent;
use Kanvas\Intelligence\Agents\Listeners\RespondToAgentMentionListener;
use Kanvas\Intelligence\Agents\Neuron\RAG\Listeners\QueueChannelKnowledgeIndexListener;
use Kanvas\Intelligence\Agents\Neuron\RAG\Listeners\QueueKnowledgeIndexListener;
use Kanvas\Intelligence\Knowledge\Events\KnowledgeIndexRequested;
use Kanvas\Intelligence\Knowledge\Listeners\QueueVariantInterestReindexListener;
use Kanvas\Inventory\Categories\Observers\ProductsCategoriesObserver;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Channels\Observers\ChannelObserver;
use Kanvas\Inventory\Channels\Observers\VariantsChannelObserver;
use Kanvas\Inventory\Products\Models\ProductsCategories;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Inventory\ProductsTypes\Observers\ProductsTypesObserver;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Regions\Observers\RegionObserver;
use Kanvas\Inventory\Status\Models\Status;
use Kanvas\Inventory\Status\Observers\StatusObserver;
use Kanvas\Inventory\Variants\Events\VariantSearchDocumentChanged;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Inventory\Warehouses\Observers\WarehouseObserver;
use Kanvas\NervousSystem\Plan\Events\PlanBroadcast;
use Kanvas\NervousSystem\Plan\Listeners\NotifyPlanCreatorOfAgentProgressListener;
use Kanvas\NervousSystem\Plan\Listeners\PushPlanChangeToKanbanListener;
use Kanvas\NervousSystem\Plan\Listeners\SyncKanbanAfterChatListener;
use Kanvas\NervousSystem\Plan\Listeners\WakeAgentOnPlanChangeListener;
use Kanvas\Notifications\Events\PushNotificationsEvent;
use Kanvas\Notifications\Listeners\NotificationsListener;
use Kanvas\Social\Channels\Events\ChannelMessageAttachedEvent;
use Kanvas\Social\Messages\Events\AppModuleMessageCreatedEvent;
use Kanvas\Social\Messages\Events\MessageMentionsStoredEvent;
use Kanvas\Social\Messages\Listeners\NotifyMentionedUsersListener;
use Kanvas\Social\Messages\Models\UserMessageActivity;
use Kanvas\Social\Messages\Observers\UserMessageActivityObserver;
use Kanvas\Social\UsersLists\Models\UserList;
use Kanvas\Social\UsersLists\Observers\UsersListsObserver;
use Kanvas\Subscription\Subscriptions\Listeners\CompanySubscriptionWebhookListener;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Kanvas\Subscription\Subscriptions\Observers\AppsStripeCustomerObserver;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Observers\UsersObserver;
use Laravel\Cashier\Events\WebhookHandled;
use Override;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        PushNotificationsEvent::class => [
            NotificationsListener::class,
        ],
        PlanBroadcast::class => [
            WakeAgentOnPlanChangeListener::class,
            PushPlanChangeToKanbanListener::class,
            NotifyPlanCreatorOfAgentProgressListener::class,
        ],
        AgentChatResponseEvent::class => [
            SyncKanbanAfterChatListener::class,
        ],
        AgentDeploymentStatusChanged::class => [
            SendAgentDeploymentLifecycleEmailListener::class,
        ],
        'LaravelCart.Added' => [
            CartListener::class,
        ],
        'LaravelCart.Updated' => [
            CartListener::class,
        ],
        'LaravelCart.Removed' => [
            CartListener::class,
        ],
        WebhookHandled::class => [
            CompanySubscriptionWebhookListener::class,
        ],
        AppModuleMessageCreatedEvent::class => [
            UpdatePeopleMessageTimestampsListener::class,
        ],
        ChannelMessageAttachedEvent::class => [
            QueueChannelKnowledgeIndexListener::class,
        ],
        KnowledgeIndexRequested::class => [
            QueueKnowledgeIndexListener::class,
        ],
        VariantSearchDocumentChanged::class => [
            QueueVariantInterestReindexListener::class,
        ],
        MessageMentionsStoredEvent::class => [
            RespondToAgentMentionListener::class,
            NotifyMentionedUsersListener::class,
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    #[Override]
    public function boot()
    {
        Users::observe(UsersObserver::class);
        CompaniesGroups::observe(CompaniesGroupsObserver::class);
        UserMessageActivity::observe(UserMessageActivityObserver::class);
        UserList::observe(UsersListsObserver::class);
        Lead::observe(LeadObserver::class);
        Warehouses::observe(WarehouseObserver::class);
        Regions::observe(RegionObserver::class);
        Status::observe(StatusObserver::class);
        Channels::observe(ChannelObserver::class);
        ProductsTypes::observe(ProductsTypesObserver::class);
        VariantsChannels::observe(VariantsChannelObserver::class);
        ProductsCategories::observe(ProductsCategoriesObserver::class);
        PeopleEmploymentHistory::observe(PeopleEmploymentHistoryObserver::class);
        People::observe(PeopleObserver::class);
        AppsStripeCustomer::observe(AppsStripeCustomerObserver::class);
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool
     */
    #[Override]
    public function shouldDiscoverEvents()
    {
        return true;
    }
}
