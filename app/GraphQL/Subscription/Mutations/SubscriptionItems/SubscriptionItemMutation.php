<?php

declare(strict_types=1);

namespace App\GraphQL\Subscription\Mutations\SubscriptionItems;

use Kanvas\Subscription\SubscriptionItems\Actions\CreateSubscriptionItem;
use Kanvas\Subscription\SubscriptionItems\Actions\UpdateSubscriptionItem;
use Kanvas\Subscription\SubscriptionItems\Repositories\SubscriptionItemRepository;
use Kanvas\Subscription\SubscriptionItems\DataTransferObject\SubscriptionItem as SubscriptionItemDto;
use Kanvas\Subscription\SubscriptionItems\Models\SubscriptionItem as SubscriptionItemModel;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Illuminate\Support\Facades\Auth;
use Stripe\Stripe;
use Stripe\SubscriptionItem as StripeSubscriptionItem;

class SubscriptionItemMutation
{
    public function __construct()
    {
        $app = app(Apps::class);
        Stripe::setApiKey($app->get('stripe_secret'));
    }

    /**
     * create.
     *
     * @param  array $args
     * @return SubscriptionItemModel
     */
    public function create(array $args): SubscriptionItemModel
    {
        $data = $args['input'];
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $dto = SubscriptionItemDto::viaRequest(array_merge($data, [
            'apps_id' => $app->id,
            'companies_id' => $company->id,
        ]), $user, $company, $app);

        $action = new CreateSubscriptionItem($dto);
        $subscriptionItemModel = $action->execute();

        return $subscriptionItemModel;
    }

    /**
     * update.
     *
     * @param  array $req
     *
     * @return SubscriptionItemModel
     */
    public function update(array $req): SubscriptionItemModel
    {
        $app = app(Apps::class);
        $company = Companies::findOrFail($req['input']['company_id']);

        $subscriptionItem = SubscriptionItemRepository::getById($req['id']);

        StripeSubscriptionItem::update($subscriptionItem->stripe_id, [
            'price' => $req['input']['stripe_price_id'],
            'quantity' => $req['input']['quantity'] ?? $subscriptionItem->quantity,
        ]);

        $dto = SubscriptionItemDto::viaRequest($req['input'], Auth::user(), $company, $app);

        $action = new UpdateSubscriptionItem($subscriptionItem, $dto);
        $updatedSubscriptionItem = $action->execute();

        return $updatedSubscriptionItem;
    }

    /**
     * delete.
     *
     * @param  array $req
     *
     * @return bool
     */
    public function delete(mixed $root, array $req): bool
    {
        $subscriptionItem = SubscriptionItemRepository::getById($req['id']);

        if ($subscriptionItem->subscription_id !== $req['subscription_id']) {
            throw new \Exception('The SubscriptionItem does not belong to the specified subscription.');
        }
        
        $stripeSubscriptionItem = StripeSubscriptionItem::retrieve($subscriptionItem->stripe_id);
        $stripeSubscriptionItem->delete();

        $subscriptionItem->delete();

        return true;
    }
}
