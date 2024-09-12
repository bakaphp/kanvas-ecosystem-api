<?php

declare(strict_types=1);

namespace App\GraphQL\Subscriptions\Mutations\SubscriptionItems;

use Kanvas\Subscription\SubscriptionItems\Actions\CreateSubscriptionItem;
use Kanvas\Subscription\SubscriptionItems\Actions\UpdateSubscriptionItem;
use Kanvas\Subscription\SubscriptionItems\Repositories\SubscriptionItemRepository;
use Kanvas\Subscription\SubscriptionItems\DataTransferObject\SubscriptionItem as SubscriptionItemDto;
use Kanvas\Subscription\SubscriptionItems\Models\SubscriptionItem as SubscriptionItemModel;
use Illuminate\Support\Facades\Auth;
use Stripe\Stripe;
use Stripe\SubscriptionItem as StripeSubscriptionItem;

class SubscriptionItemMutation
{
    public function __construct()
    {
        Stripe::setApiKey(env('STRIPE_SECRET'));
    }

    /**
     * create.
     *
     * @param  array $req
     *
     * @return SubscriptionItemModel
     */
    public function create(array $req): SubscriptionItemModel
    {

        StripeSubscriptionItem::create([
            'subscription' => $req['input']['subscription_id'],
            'price' => $req['input']['stripe_price_id'],
            'quantity' => $req['input']['quantity'] ?? 1,
        ]);

        $dto = SubscriptionItemDto::viaRequest($req['input'], Auth::user());

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

        $subscriptionItem = SubscriptionItemRepository::getById($req['id']);

        StripeSubscriptionItem::update($subscriptionItem->stripe_id, [
            'price' => $req['input']['stripe_price_id'],
            'quantity' => $req['input']['quantity'] ?? $subscriptionItem->quantity,
        ]);

        $dto = SubscriptionItemDto::viaRequest($req['input'], Auth::user());

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
    public function delete(array $req): bool
    {
        $subscriptionItem = SubscriptionItemRepository::getById($req['id']);

        $stripeSubscriptionItem = StripeSubscriptionItem::retrieve($subscriptionItem->stripe_id);

        $stripeSubscriptionItem->delete();

        $subscriptionItem->delete();

        return true;
    }
}
