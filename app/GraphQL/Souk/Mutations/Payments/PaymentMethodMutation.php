<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Payments;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\EchoPay\DataTransferObject\BillingDetail;
use Kanvas\Connectors\EchoPay\DataTransferObject\CardDetail;
use Kanvas\Connectors\EchoPay\DataTransferObject\CardTokenization;
use Kanvas\Connectors\EchoPay\DataTransferObject\MerchantDetail;
use Kanvas\Connectors\EchoPay\Services\EchoPayService;
use Kanvas\Payments\Actions\CreatePaymentMethodAction;
use Kanvas\Payments\Actions\UpdatePaymentMethodAction;
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
        // TODO: move this to a provider centry to avoid hardcoding here
        if ($input['processor'] == 'portal') {
            $portalService = new EchoPayService($app, $company);
            $card = CardTokenization::fromRequest($input, $app, $user);
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
                    'country' => $input['country'],
                    'city' => $input['city'],
                    'address' => $input['address'],
                    'phone' => $input['phone'],
                    'zip_code' => $input['zip_code'],
                    'state' => $input['state']
                ]
            );
            $action = new CreatePaymentMethodAction($paymentMethod);
            return $action->execute();
        }

        throw new Exception('Processor not supported');
    }

    public function updatePaymentMethod($_, array $request): PaymentMethods
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $request['input'];
        $card = null;

        $paymentMethod = PaymentMethods::fromCompany($company)->fromApp($app)->where([
            'id' => $request['id'],
        ])->first();

        if (!$paymentMethod) {
            throw new Exception('Payment method not found');
        }

        if ($paymentMethod->processor == 'portal') {
            $portalService = new EchoPayService($app, $company);
            $card = CardTokenization::fromRequest($input, $app, $user);
            $tokenizedCard = $portalService->updateCard($paymentMethod->stripe_card_id, $card);
            $paymentMethodUpdateData = new PaymentMethod(
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
                    'country' => $input['country'],
                    'city' => $input['city'],
                    'address' => $input['address'],
                    'phone' => $input['phone'],
                    'zip_code' => $input['zip_code'],
                    'state' => $input['state']
                ]
            );
            $action = new UpdatePaymentMethodAction($paymentMethod->id, $paymentMethodUpdateData);
            return $action->execute();
        }

        throw new Exception('Processor not supported');
    }

    public function deletePaymentMethod($_, array $request): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $paymentMethod = PaymentMethods::fromCompany($company)->fromApp($app)->where([
            'id' => $request['id'],
        ])->first();
        
        if (!$paymentMethod) {
            throw new Exception('Payment method not found');
        }

        if ($paymentMethod->processor == 'portal') {
            $portalService = new EchoPayService($app, $company);
            $portalService->deleteCard($paymentMethod->stripe_card_id, $paymentMethod->merchant_detail);
        }

        return $paymentMethod->delete();
    }
}
