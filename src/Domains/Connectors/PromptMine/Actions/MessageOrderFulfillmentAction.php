<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Actions;

use Kanvas\Social\Messages\Models\Message;
use Kanvas\Souk\Orders\Enums\OrderFulfillmentStatusEnum;
use Kanvas\Souk\Orders\Models\Order;

class MessageOrderFulfillmentAction
{
    public function __construct(protected Message $message)
    {
    }

    public function execute(bool $generationFailed = false): void
    {
        // check user for orders
        // check if order avaible is from the same module or class the user is strying to execute
        // if $generationFailed is false, we will marke the order we found as fulffilled
        // if not , we will keep it pending but add the user credits so frontend que validate
        $orders = Order::fromApp($this->message->app)
                        ->where('users_id', $this->message->users_id)
                        ->where('fulfillment_status', OrderFulfillmentStatusEnum::PENDING->value)
                        ->get();

        if ($orders->count()) {
            foreach ($orders as $order) {
                if ($generationFailed === false) {
                    $order->fulfillment_status = OrderFulfillmentStatusEnum::COMPLETED->value;
                    $order->save();
                } else { // keep it pending but add the user credits so frontend que validate
                    //$order->addUserCredits();
                    $this->addUserCredit($order);
                }

                return;
            }
        }
    }

    private function addUserCredit(Order $order): void
    {
        // Logic to add user credits
        $order->user->set('order_credits', [
            'image' => [
                'gpt-image-1' => 1,
            ],
        ]);
    }
}
