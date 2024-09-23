<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Kanvas\Companies\Groups\Observers\CompaniesGroupsObserver;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesGroups;
use Kanvas\Companies\Observers\CompaniesObserver;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Models\PeopleEmploymentHistory;
use Kanvas\Guild\Customers\Observers\PeopleEmploymentHistoryObserver;
use Kanvas\Guild\Customers\Observers\PeopleObserver;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Observers\LeadObserver;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Attributes\Observers\AttributeObserver;
use Kanvas\Inventory\Categories\Observers\ProductsCategoriesObserver;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Channels\Observers\ChannelObserver;
use Kanvas\Inventory\Channels\Observers\VariantsChannelObserver;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Products\Models\ProductsCategories;
use Kanvas\Inventory\Products\Observers\ProductsObserver;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Inventory\ProductsTypes\Observers\ProductsTypesObserver;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Regions\Observers\RegionObserver;
use Kanvas\Inventory\Status\Models\Status;
use Kanvas\Inventory\Status\Observers\StatusObserver;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Variants\Observers\VariantObserver;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Inventory\Warehouses\Observers\VariantsWarehouseObserver;
use Kanvas\Inventory\Warehouses\Observers\WarehouseObserver;
use Kanvas\Notifications\Events\PushNotificationsEvent;
use Kanvas\Notifications\Listeners\NotificationsListener;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Models\UserMessage;
use Kanvas\Social\Messages\Models\UserMessageActivity;
use Kanvas\Social\Messages\Observers\MessageObserver;
use Kanvas\Social\Messages\Observers\UserMessageActivityObserver;
use Kanvas\Social\Messages\Observers\UserMessageObserver;
use Kanvas\Social\UsersLists\Models\UserList;
use Kanvas\Social\UsersLists\Observers\UsersListsObserver;
use Kanvas\Users\Models\UserCompanyApps;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Kanvas\Users\Observers\UsersAssociatedAppsObserver;
use Kanvas\Users\Observers\UsersAssociatedCompaniesObserver;
use Kanvas\Users\Observers\UsersObserver;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        PushNotificationsEvent::class => [
            NotificationsListener::class,
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        Users::observe(UsersObserver::class);
        Companies::observe(CompaniesObserver::class);
        CompaniesGroups::observe(CompaniesGroupsObserver::class);
        UserMessageActivity::observe(UserMessageActivityObserver::class);
        UserList::observe(UsersListsObserver::class);
        Lead::observe(LeadObserver::class);
        UserMessage::observe(UserMessageObserver::class);
        Message::observe(MessageObserver::class);
        Warehouses::observe(WarehouseObserver::class);
        Regions::observe(RegionObserver::class);
        Status::observe(StatusObserver::class);
        VariantsWarehouses::observe(VariantsWarehouseObserver::class);
        Channels::observe(ChannelObserver::class);
        Products::observe(ProductsObserver::class);
        ProductsTypes::observe(ProductsTypesObserver::class);
        Variants::observe(VariantObserver::class);
        VariantsChannels::observe(VariantsChannelObserver::class);
        Attributes::observe(AttributeObserver::class);
        UsersAssociatedApps::observe(UsersAssociatedAppsObserver::class);
        UserCompanyApps::observe(UsersAssociatedCompaniesObserver::class);
        ProductsCategories::observe(ProductsCategoriesObserver::class);
        PeopleEmploymentHistory::observe(PeopleEmploymentHistoryObserver::class);
        People::observe(PeopleObserver::class);
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool
     */
    public function shouldDiscoverEvents()
    {
        return true;
    }
}
