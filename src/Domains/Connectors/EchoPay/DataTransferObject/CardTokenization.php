<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\DataTransferObject;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EchoPay\Enums\ConfigurationEnum;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Data;

class CardTokenization extends Data
{
    public function __construct(
        public readonly CardDetail $card,
        public readonly BillingDetail $billTo,
        public readonly MerchantDetail $merchant,
    ) {
    }

    public static function fromRequest(array $request, Apps $app, Users $user): self
    {
        [$year, $month] = explode('-', $request['expiration_date']);

        return new self(
            card: new CardDetail(
                number: $request['number'] ?? '',
                expirationMonth: $month,
                expirationYear: $year,
                type: $request['brand'] ?? '',
            ),
            billTo: new BillingDetail(
                firstName: $request['firstname'] ?? $user->firstname,
                lastName: $request['lastname'] ?? $user->lastname,
                email: $user->email,
                country: $request['country'] ?? '',
                city: $request['city'] ?? '',
                address1: $request['address'] ?? '',
                phone: $request['phone'] ?? '',
                postalCode: $request['zip_code'] ?? '',
                administrativeArea: $request['state'] ?? '',
            ),
            merchant: MerchantDetail::from(
                [
                    'id' => $app->get(ConfigurationEnum::MERCHANT_ID->value),
                    'key' => $app->get(ConfigurationEnum::MERCHANT_KEY->value),
                    'secretKey' => $app->get(ConfigurationEnum::MERCHANT_SECRET->value),
                ]
            )
        );
    }
}
