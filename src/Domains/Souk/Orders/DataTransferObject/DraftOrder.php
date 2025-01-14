<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Address as ModelsAddress;
use Kanvas\Guild\Customers\Models\People as ModelsPeople;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

class DraftOrder extends Data
{
    public function __construct(
        public readonly Apps $app,
        public readonly CompaniesBranches $branch,
        public readonly Regions $region,
        public readonly Users $user,
        public readonly string $email,
        public readonly ModelsPeople $people,
        public readonly float $total,
        public readonly float $taxes,
        public readonly float $totalDiscount,
        public readonly float $totalShipping,
        public readonly string $status, //enums
        public readonly Currencies $currency,
        #[DataCollectionOf(OrderItem::class)]
        public readonly DataCollection $items,
        public readonly array $paymentGatewayName = [],
        public readonly ?ModelsAddress $billingAddress = null,
        public readonly ?ModelsAddress $shippingAddress = null,
        public readonly ?string $phone = null,
        public readonly ?string $notes = null,
        public readonly mixed $metadata = null,
    ) {
    }

    public static function viaRequest(AppInterface $app, CompaniesBranches $branch, UserInterface $user, Regions $region, array $request): self
    {
        $customer = $request['input']['customer'];
        $customer['contacts'] = [
            [
                'value' => $request['input']['email'],
                'contacts_types_id' => ContactTypeEnum::EMAIL->value,
                'weight' => 0,
            ],
        ];

        if (! empty($request['input']['phone'])) {
            $customer['contacts'][] = [
                'value' => $request['input']['phone'],
                'contacts_types_id' => ContactTypeEnum::PHONE->value,
                'weight' => 0,
            ];
        }

        $people = People::from([
            'app' => $app,
            'branch' => $branch,
            'user' => $user,
            'firstname' => $customer['firstname'],
            'middlename' => $customer['middlename'] ?? null,
            'lastname' => $customer['lastname'] ?? null,
            'contacts' => Contact::collect($customer['contacts'] ?? [], DataCollection::class),
            'address' => Address::collect([], DataCollection::class),
            'id' => $data['id'] ?? 0,
            'dob' => $data['dob'] ?? null,
            'facebook_contact_id' => $data['facebook_contact_id'] ?? null,
            'google_contact_id' => $data['google_contact_id'] ?? null,
            'apple_contact_id' => $data['apple_contact_id'] ?? null,
            'linkedin_contact_id' => $data['linkedin_contact_id'] ?? null,
            'tags' => $data['tags'] ?? [],
            'custom_fields' => $data['custom_fields'] ?? [],
        ]);

        $people = (new CreatePeopleAction($people))->execute();

        $shippingAddress = ! empty($request['input']['shipping_address']['address1']) ?
            $customer->addAddress(new Address(
                address: $request['input']['shipping_address']['address1'] ?? '',
                address_2: $request['input']['shipping_address']['address2'] ?? '',
                city: $request['input']['shipping_address']['city'] ?? '',
                state: $request['input']['shipping_address']['province'] ?? '',
                country: $request['input']['shipping_address']['country'] ?? '',
                zip: $request['input']['shipping_address']['zip'] ?? ''
            ))
            : null;

        $billingAddress = ! empty($request['input']['billing_address']['address1']) ?
            $customer->addAddress(new Address(
                address: $request['input']['billing_address']['address1'],
                address_2: $request['input']['billing_address']['address2'],
                city: $request['input']['billing_address']['city'],
                state: $request['input']['billing_address']['province'],
                country: $request['input']['billing_address']['country'],
                zip: $request['input']['billing_address']['zip']
            ))
            : null;

        $total = 0;
        $totalTax = 0;
        $totalDiscount = 0;
        $totalShipping = 0;
        $lineItems = [];
        foreach ($request['input']['items'] as $key => $lineItem) {
            $lineItems[$key] = OrderItem::viaRequest($app, $branch->company, $region, $lineItem);
            $total += $lineItems[$key]->getTotal();
            $totalTax = $lineItems[$key]->getTotalTax();
            $totalDiscount = $lineItems[$key]->getTotalDiscount();
        }

        return new self(
            $app,
            $branch,
            $region,
            $user,
            $request['input']['email'],
            $people,
            $total,
            $totalTax,
            $totalDiscount,
            $totalShipping,
            'draft',
            $region->currency,
            OrderItem::collect($lineItems, DataCollection::class),
            ['manual'],
            $shippingAddress,
            $billingAddress,
            $request['input']['phone'] ?? null,
            $request['input']['notes'] ?? null,
            $request['input']['metadata'] ?? null,
        );
    }
}
