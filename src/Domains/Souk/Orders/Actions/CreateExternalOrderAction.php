<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Baka\Support\Str;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\DataTransferObject\Order;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order as OrderModel;
use Kanvas\Souk\Orders\Models\OrderItem as OrderItemModel;
use Spatie\LaravelData\DataCollection;

class CreateExternalOrderAction
{
    public bool $runWorkflow = true;

    public function __construct(
        protected OrderModel $order,
        protected OrderItemModel $orderItem,
    ) {
    }

    public function execute(): OrderModel
    {
        $orderItemsCollection = [];
        $total = 0;
        $variant = Variants::findOrFail($this->orderItem->variant_id);
        $orderCurrency = $eventVersion->currency ?? Currencies::getByCode('DOP');
        $order = $this->order;
        $orderItem = $this->orderItem;

        $orderItem = new OrderItem(
            app: $order->app,
            variant: $variant,
            name: $variant->name,
            sku: $variant->sku,
            quantity: $orderItem->quantity,
            price: $orderItem->unit_price_net_amount,
            tax: 0,
            discount: 0.0,
            currency: $orderCurrency,
            quantityShipped: 0
        );

        $orderItemsCollection[] = $orderItem;
        $total = $orderItem->getTotal();


        $people = PeoplesRepository::getByEmail($order->user->email, $order->company, $order->app);
        if (! $people) {
            $contacts = [
                new Contact(
                    value: $order->user->email,
                    contacts_types_id: ContactTypeEnum::EMAIL->value,
                    weight: 0
                ),
            ];
            $peopleDto = new People(
                app: $order->app,
                branch: $order->company->defaultBranch,
                user: $order->user,
                firstname: $order->user->firstname,
                lastname: $order->user->lastname,
                contacts: Contact::collect($contacts, DataCollection::class),
                address: Address::collect([], DataCollection::class)
            );
            $people = (new CreatePeopleAction(
                $peopleDto
            ))->execute();
        }

        $items = OrderItem::collect($orderItemsCollection, DataCollection::class);

        $dto = Order::from([
            'app' => $order->app,
            'region' => Regions::getDefault($variant->company, $variant->app),
            'token' => Str::random(32),
            'company' => $variant->company,
            'people' => $people,
            'user' => $order->user,
            'orderNumber' => '',
            'orderType' => $order->orderType?->name,
            'user_email' => $order->user_email,
            'user_phone' => $order->user_phone,
            'total' => (float) $total,
            'taxes' => 0.0,
            'totalDiscount' => 0.0,
            'totalShipping' => 0.0,
            'status' => OrderStatusEnum::COMPLETED->value,
            'checkoutToken' => '',
            'currency' => $orderCurrency,
            'items' => $items,
            'parent' => $order,
            'ipAddress' => $order->ip_address,
        ]);
        $action = new CreateOrderAction($dto);
        $action->disableWorkflow();
        $kanvasOrder = $action->execute();
        $kanvasOrder->resources_id = $order->id;
        $kanvasOrder->resources_type = $order->getMorphClass();
        $kanvasOrder->saveQuietly();

        return $kanvasOrder;
    }
}
