<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Payments;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\EchoPay\DataTransferObject\BillingDetailData;
use Kanvas\Connectors\EchoPay\DataTransferObject\CardDetailData;
use Kanvas\Connectors\EchoPay\DataTransferObject\CardTokenizationData;
use Kanvas\Connectors\EchoPay\DataTransferObject\MerchantDetailData;
use Kanvas\Connectors\EchoPay\Services\EchoPayService;
use Kanvas\Payments\Actions\CreatePaymentMethodAction;
use Kanvas\Payments\DataTransferObjet\PaymentMethod;
use Kanvas\Payments\Models\PaymentMethods;

class PaymentMethodMutation
{
    public function createPaymentMethod($_, array $request): PaymentMethods
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $companiesId = auth()->user()->currentCompanyId();
        $company = Companies::find($companiesId);
        $input = $request['input'];
        $card = null;
        if ($input['processor'] == 'portal') {
            [$year, $month] = explode('-', $input['expiration_date']);
            $portalService = new EchoPayService($app, $company);
            $card = new CardTokenizationData(
                card: new CardDetailData(
                    number: $input['number'],
                    expirationMonth: $month,
                    expirationYear: $year,
                    type: $input['brand'],
                ),
                billTo: new BillingDetailData(
                    firstName: $user->firstname,
                    lastName: $user->lastname,
                    country: $company->country,
                    city: $company->city,
                    address1: $company->address,
                    phone: $user->phone_number,
                    email: $user->email,
                    postalCode: $company->zip,
                    administrativeArea: $company->state,
                ),
                merchant: MerchantDetailData::from([
                    'id' => $app->get('ECHO_PAY_MERCHANT_ID'),
                    'key' => $app->get('ECHO_PAY_MERCHANT_KEY'),
                    'secretKey' => $app->get('ECHO_PAY_MERCHANT_SECRET')
                ]),
            );
            $tokenizedCard = $portalService->addCard($card);
            $paymentMethod = new PaymentMethod(
                app: $app,
                user: $user,
                company: $company,
                payment_ending_numbers: $tokenizedCard['cardNumber'],
                payment_methods_brand: $input['brand'],
                stripe_card_id: $tokenizedCard['paymentInstrumentId'],
                expiration_date: $input['expiration_date'],
                zip_code: $card->billTo->postalCode,
                processor: $input['processor'] ?? null,
                metadata: $request['metadata'] ?? [
                    ...$tokenizedCard,
                ]
            );
            $action = new CreatePaymentMethodAction($paymentMethod);
            return $action->execute();
        }
    }
}
