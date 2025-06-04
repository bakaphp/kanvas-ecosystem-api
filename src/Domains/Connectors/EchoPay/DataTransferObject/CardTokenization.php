<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\DataTransferObject;

use Kanvas\Apps\Models\Apps;
use Spatie\LaravelData\Data;
use Kanvas\Users\Models\Users;

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
                number: $request['number'],
                expirationMonth: $month,
                expirationYear: $year,
                type: $request['brand'],
            ),
            billTo: new BillingDetail(
                firstName: $user->firstname,
                lastName: $user->lastname,
                email: $user->email,
                country: $request['country'],
                city: $request['city'],
                address1: $request['address'],
                phone: $request['phone'],
                postalCode: $request['zip_code'],
                administrativeArea: $request['state'],
            ),
            merchant: MerchantDetail::from([
                'id' => $app->get('ECHO_PAY_MERCHANT_ID'),
                'key' => $app->get('ECHO_PAY_MERCHANT_KEY'),
                'secretKey' => $app->get('ECHO_PAY_MERCHANT_SECRET')
            ]
            )
        );
    }
}
