<?php

namespace Kanvas\Connectors\Movipass\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Tookan\Enums\OrderTypeEnum;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Actions\CreateOrderAction;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;
use Spatie\LaravelData\DataCollection;

class SyncTookanOrderActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $order, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::TOOKAN,
            additionalParams: $params,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) use ($params) {
                if ($order->orderType->name !== OrderTypeEnum::DELIVERY->value) {
                    return [
                        'order' => $order->getId(),
                        'status' => 'success',
                        'message' => 'Order is not a delivery order, skipping sync',
                    ];
                }

                $eventName = $additionalParams['currentEventTypeName'] ?? null;
                if ($eventName == WorkflowEnum::CREATED->value) {
                    $externalItem = $order->items->first(function ($item) use ($order) {
                        return $item->variant->companies_id !== $order->companies_id;
                    });

                    $externalOrder = $this->createExternalOrder($order, $externalItem);

                    $order->status = OrderStatusEnum::PENDING->value;
                    $order->saveQuietly();
                }

                if ($eventName === WorkflowEnum::STATUS_TRANSITION->value) {
                    $toStatus = $params['to_status'] ?? null;

                    if ($toStatus === MovipassOrderStatusEnum::PAID->value) {
                        // @TODO: to be defined in another ticket
                    }

                    if ($toStatus === MovipassOrderStatusEnum::RELEASED->value) {
                        // @TODO: to be defined in another ticket
                    }
                }

                return [
                    'order' => $order->getId(),
                    'status' => 'success',
                    'message' => 'Order synced correctly',
                    'data' => $order->toArray(),
                    'response' => $order->toArray(),
                ];
            },
            company: $order->company,
        );
    }

    protected function createExternalOrder(Order $order, OrderItem $orderItem): void
    {
        $orderItemsCollection = [];
        $total = 0;
        $variant = Variants::findOrFail($orderItem->variants_id);
        $orderCurrency = $eventVersion->currency ?? Currencies::getByCode('DOP');


        $orderItem = new OrderItem(
            app: $order->app,
            variant: $variant,
            name: $variant->name,
            sku: $variant->sku,
            quantity: $orderItem->quantity,
            price: $orderItem->price,
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
            'orderType' => 'event',
            'total' => (float) $total,
            'taxes' => 0.0,
            'totalDiscount' => 0.0,
            'totalShipping' => 0.0,
            'status' => OrderStatusEnum::COMPLETED->value,
            'checkoutToken' => '',
            'currency' => $orderCurrency,
            'items' => $items,
        ]);
        $action = new CreateOrderAction($dto);
        $action->disableWorkflow();
        $kanvasOrder = $action->execute();
        $kanvasOrder->resources_id = $order->id;
        $kanvasOrder->resources_type = $order->getMorphClass();
        $kanvasOrder->saveQuietly();
    }
}
