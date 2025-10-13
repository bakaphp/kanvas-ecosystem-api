<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Actions;

use Baka\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Event\Events\DataTransferObject\Event;
use Kanvas\Event\Events\DataTransferObject\EventVersion;
use Kanvas\Event\Events\Models\Event as ModelsEvent;
use Kanvas\Event\Events\Models\EventResource;
use Kanvas\Event\Events\Validators\EventTimeSlotValidator;
use Kanvas\Event\Participants\Actions\CreateParticipantAction;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Actions\CreateOrderAction;
use Kanvas\Souk\Orders\DataTransferObject\Order;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\SystemModules\Models\SystemModules;
use Spatie\LaravelData\DataCollection;

class CreateEventAction
{
    public function __construct(
        protected Event $event
    ) {
    }

    public function execute(): ModelsEvent
    {
        // Validate time slot availability
        $this->validateTimeSlotAvailability();

        $event = DB::connection('event')->transaction(function () {
            $slug = $this->event->slug ?? Str::slug($this->event->name);
            //Slug no attached to the event type id , idk why
            $slug = $slug . '-' . $this->event->type->getId();
            // $this->validateSlug($slug);
            $event = ModelsEvent::updateOrCreate([
                'apps_id' => $this->event->app->getId(),
                'companies_id' => $this->event->company->getId(),
                'users_id' => $this->event->user->getId(),
                'name' => $this->event->name,
                'theme_id' => $this->event->theme->getId(),
                'theme_area_id' => $this->event->themeArea->getId(),
                'event_status_id' => $this->event->status->getId(),
                'event_type_id' => $this->event->type->getId(),
                'event_category_id' => $this->event->category->getId(),
                'event_class_id' => $this->event->class->getId(),
                'description' => $this->event->description,
                'resources_id' => $this->event->resource?->id ?? null,
                'resources_type' => $this->event->resource?->getMorphClass() ?? null,
                'slug' => $slug,
                'meeting_link' => $this->event->meeting_link,
            ]);
            if ($this->event->dates->count()) {
                $eventVersionSlug = Str::slug('events-versions-' . $slug . $this->event->dates[0]->date->format('Y-m-d'));
            } else {
                $eventVersionSlug = Str::slug('events-versions-' . $slug);
            }
            $eventVersionAction = new CreateEventVersionAction(
                new EventVersion(
                    event: $event,
                    user: $this->event->user,
                    currency: Currencies::getByCode('USD'),
                    name: $this->event->name,
                    version: 1,
                    description: $this->event->description,
                    pricePerTicket: 0,
                    dates: $this->event->dates,
                    slug: $eventVersionSlug
                )
            );

            $eventVersion = $eventVersionAction->execute();
            foreach ($this->event->participants as $participant) {
                $createParticipant = new CreateParticipantAction(
                    $this->event->app,
                    $this->event->company->defaultBranch,
                    $this->event->user,
                    $participant,
                    $eventVersion,
                    $participant
                );
                $createParticipant->execute();
            }

            if ($event->resources_id && ! $event->orders->count()) {
                $this->createEventOrder($event, $this->event->orderItems);
            }

            // Store additional resources in pivot table
            if (count($this->event->resources)) {
                $this->storeEventResources($event, $this->event->resources);
            }

            return $event;
        });

        return $event;
    }

    protected function validateSlug(string $slug): void
    {
        Validator::make(
            ['slug' => $slug],
            [
                'slug' => [
                    'required',
                    // Custom rule using DB to specify the connection and validate uniqueness.
                    function ($attribute, $value, $fail) {
                        $exists = DB::connection('event') // Replace with your DB connection name.
                            ->table('events')
                            ->where('slug', $value)
                            ->where('apps_id', $this->event->app->getId())
                            ->where('companies_id', $this->event->company->getId())
                            ->exists();

                        if ($exists) {
                            $fail('The ' . $attribute . ' has already been taken.');
                        }
                    },
                ],
            ]
        )->validate();
    }

    protected function createEventOrder(ModelsEvent $event, array $orderItemsData = []): void
    {
        $variant = $event->resource;

        if (! $variant) {
            return;
        }

        $orderItemsCollection = [];
        $total = 0;

        // If order_items are provided, create OrderItem DTOs from them
        if (! empty($orderItemsData)) {
            foreach ($orderItemsData as $itemData) {
                $itemVariant = Variants::getById($itemData['variant_id'], $event->app);

                $orderItem = new OrderItem(
                    app: $event->app,
                    variant: $itemVariant,
                    name: $itemData['name'],
                    sku: $itemVariant->sku,
                    quantity: $itemData['quantity'],
                    price: (float) ($itemData['price'] ?? 0.0),
                    tax: 0,
                    discount: 0.0,
                    currency: $itemData['currency_code'] ?? Currencies::getByCode('USD'),
                    quantityShipped: 0,
                    metadata: $itemData['metadata'] ?? null
                );

                $orderItemsCollection[] = $orderItem;
                $total += $orderItem->getTotal();
            }
        } else {
            // Default: create single order item for the main resource
            $orderItem = new OrderItem(
                app: $event->app,
                variant: $variant,
                name: $event->name,
                sku: $variant->sku,
                quantity: 1,
                price: 0,
                tax: 0,
                discount: 0.0,
                currency: Currencies::getByCode('USD'),
                quantityShipped: 0
            );

            $orderItemsCollection[] = $orderItem;
            $total = $orderItem->price;
        }

        $people = PeoplesRepository::getByEmail($event->user->email, $event->company, $event->app);
        if (! $people) {
            $contacts = [
                new Contact(
                    value: $event->user->email,
                    contacts_types_id: ContactTypeEnum::EMAIL->value,
                    weight: 0
                ),
            ];
            $peopleDto = new People(
                app: $event->app,
                branch: $event->company->defaultBranch,
                user: $event->user,
                firstname: $event->user->firstname,
                lastname: $event->user->lastname,
                contacts: Contact::collect($contacts, DataCollection::class),
                address: Address::collect([], DataCollection::class)
            );
            $people = (new CreatePeopleAction(
                $peopleDto
            ))->execute();
        }

        $items = OrderItem::collect($orderItemsCollection, DataCollection::class);

        $dto = Order::from([
            'app' => $event->app,
            'region' => Regions::getDefault($event->company, $event->apps),
            'token'  => Str::random(32),
            'company' => $event->company,
            'people' => $people,
            'user' => $event->user,
            'orderNumber' => '',
            'total' => (float) $total,
            'taxes' => 0.0,
            'totalDiscount' => 0.0,
            'totalShipping' => 0.0,
            'status' => OrderStatusEnum::COMPLETED->value,
            'checkoutToken' => '',
            'currency' => Currencies::getByCode('USD'),
            'items' => $items,
        ]);
        $action = new CreateOrderAction($dto);
        $action->disableWorkflow();
        $kanvasOrder = $action->execute();
        $kanvasOrder->resources_id =  $event->id;
        $kanvasOrder->resources_type =  $event->getMorphClass();
        $kanvasOrder->saveQuietly();
    }

    protected function storeEventResources(ModelsEvent $event, array $resources): void
    {
        foreach ($resources as $resourceData) {
            if (isset($resourceData['resources_id']) && isset($resourceData['resources_type'])) {
                $resourceClass = SystemModules::getSystemModuleNameSpaceBySlug($resourceData['resources_type']);
                EventResource::create([
                    'apps_id' => $event->apps_id,
                    'companies_id' => $event->companies_id,
                    'event_id' => $event->getId(),
                    'resources_id' => $resourceData['resources_id'],
                    'resources_type' => $resourceClass,
                    'metadata' => $resourceData['metadata'] ?? null,
                ]);
            }
        }
    }

    protected function validateTimeSlotAvailability(): void
    {
        if (! $this->event->dates->count()) {
            return; // No dates to validate
        }

        $dateData = $this->event->dates[0]; // Assuming single date for now
        $resourcesId = $this->event->resource?->id;
        $resourcesType = $this->event->resource?->getMorphClass();

        if (! $resourcesId || ! $resourcesType) {
            return; // No resource to validate against
        }

        // Parse the new time slot
        $newDate = $dateData->date->format('Y-m-d');
        $newStartTime = $dateData->start_time;
        $newEndTime = $dateData->end_time;

        // Use shared validator
        EventTimeSlotValidator::validateForCreate(
            $resourcesId,
            $resourcesType,
            $this->event->company->getId(),
            $this->event->app->getId(),
            $newDate,
            $newStartTime,
            $newEndTime
        );
    }
}
