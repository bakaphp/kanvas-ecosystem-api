<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Payments;

suse Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\EchoPay\DataTransferObject\BillingDetail;
use Kanvas\Connectors\EchoPay\DataTransferObject\CardDetail;
use Kanvas\Connectors\EchoPay\DataTransferObject\CardTokenization;
use Kanvas\Connectors\EchoPay\DataTransferObject\MerchantDetail;
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
            $card = new CardTokenization(
                card: new CardDetail(
                    number: $input['number'],
                    expirationMonth: $month,
                    expirationYear: $year,
                    type: $input['brand'],
                ),
                billTo: new BillingDetail(
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
                merchant: MerchantDetail::from([
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
                payment_ending_numbers: substr($input['number'], strlen($input['number']) - 4, 4),
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

        throw new Exception('Processor not supported');
    }
}
