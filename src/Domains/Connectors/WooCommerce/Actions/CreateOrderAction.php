<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WooCommerce\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\WooCommerce\DataTransferObject\WooCommerceImportOrder;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address as AddressDto;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleDto;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Address as AddressModel;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Locations\Models\Countries;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Actions\CreateOrderAction as SoukCreateOrderAction;
use Kanvas\Souk\Orders\Models\Order as ModelsOrder;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\DataCollection;

class CreateOrderAction
{
    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected Users $user,
        protected Regions $region,
        protected object $order
    ) {
    }

    public function execute(): ModelsOrder
    {
        $people = PeoplesRepository::getByEmail($this->order->billing->email, $this->company);
        if (! $people) {
            $peopleDto = PeopleDto::from([
                'app' => $this->app,
                'branch' => $this->user->getCurrentBranch(),
                'user' => $this->user,
                'firstname' => $this->order->billing->first_name,
                'lastname' => $this->order->billing->last_name,
                'contacts' => Contact::collect([
                    [
                        'value' => $this->order->billing->email,
                        'contacts_types_id' => ContactTypeEnum::EMAIL->value,
                    ],
                ], DataCollection::class),
                'address' => AddressDto::collect([
                    [
                        'address' => $this->order->billing->address_1,
                        'address_2' => $this->order->billing->address_2,
                        'city' => $this->order->billing->city,
                        'state' => $this->order->billing->state,
                        'zip' => $this->order->billing->postcode,
                        'countries_id' => Countries::where('code', $this->order->billing->country)
                                    ->first()
                                    ->id,],
                ], DataCollection::class),
            ]);
            $createPeople = new CreatePeopleAction($peopleDto);
            $people = $createPeople->execute();
        }
        $shippingAddress = AddressModel::create([
            'address' => $this->order->shipping->address_1,
            'address_2' => $this->order->shipping->address_2,
            'city' => $this->order->shipping->city,
            'state' => $this->order->shipping->state,
            'zip' => $this->order->shipping->postcode,
            'countries_id' => Countries::where('code', $this->order->shipping->country)
                        ->first()
                        ->id,
            'peoples_id' => $people->id,
        ]);
        $billingAddress = AddressModel::firstOrCreate([
            'address' => $this->order->billing->address_1,
            'address_2' => $this->order->billing->address_2,
            'city' => $this->order->billing->city,
            'state' => $this->order->billing->state,
            'zip' => $this->order->billing->postcode,
            'countries_id' => Countries::where('code', $this->order->billing->country)
                        ->first()
                        ->id,
            'peoples_id' => $people->id,
        ]);
        $orderDto = WooCommerceImportOrder::fromWooCommerce(
            $this->app,
            $this->company,
            $this->user,
            $this->region,
            $people,
            $this->order,
            $shippingAddress,
            $billingAddress,
        );
        $createOrder = new SoukCreateOrderAction($orderDto);

        return $createOrder->execute();
    }
}
