<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Payments;

use GuzzleHttp\Exception\RequestException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Payments\Actions\CreatePaymentMethodAction;
use Kanvas\Payments\Actions\UpdatePaymentMethodAction;
use Kanvas\Payments\DataTransferObjet\PaymentMethod;
use Kanvas\Payments\Models\PaymentMethods;

class PaymentMethodMutation
{
    public function createPaymentMethod(mixed $root, array $request): PaymentMethods
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $request['input'];
        $card = null;

        try {
            // TODO: move this to a provider to avoid hardcoding here
            if ($input['processor']) {
                $processor = app("payment.{$input['processor']}");
                $input['brand'] = $this->guessCardBrand($input['number']) ?? $input['brand'] ?? null;
                // $input['state'] = $input['country'] == 'DO' ? 'DN' : $input['state'];
                $paymentMethod = $processor->addCardFromRequest($input, $user);
            } else {
                $paymentMethod = new PaymentMethod(
                    app: $app,
                    user: $user,
                    company: $company,
                    instrument_identifier_id: $input['instrument_identifier_id'] ?? '',
                    payment_ending_numbers: substr($input['number'], strlen($input['number']) - 4, 4),
                    payment_methods_brand: $this->guessCardBrand($input['number']),
                    stripe_card_id: $input['stripe_card_id'] ?? '',
                    expiration_date: $input['expiration_date'],
                    zip_code: $input['zip_code'],
                    processor: $input['processor'] ?? null,
                    metadata: $request['metadata'] ?? [
                        'country' => $input['country'],
                        'city' => $input['city'],
                        'address' => $input['address'],
                        'phone' => $input['phone'],
                        'zip_code' => $input['zip_code'],
                        'state' => $input['state'],
                        'firstname' => $input['firstname'] ?? null,
                        'lastname' => $input['lastname'] ?? null,
                    ]
                );
            }

            return new CreatePaymentMethodAction($paymentMethod)->execute();
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $errorMessage = json_decode((string) $response->getBody())->message;
            } else {
                $errorMessage = $e->getMessage();
            }

            if (is_array($errorMessage)) {
                $errorMessage = implode(', ', $errorMessage);
            }

            throw new ValidationException($errorMessage);
        }
    }

    public function updatePaymentMethod(mixed $root, array $request): PaymentMethods
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        $paymentMethod = PaymentMethods::fromCompany($company)->fromApp($app)->where([
            'id' => $request['id'],
        ])->first();

        if (! $paymentMethod) {
            throw new ValidationException('Payment method not found');
        }

        if ($paymentMethod->processor) {
            $processor = app("payment.{$paymentMethod->processor}");
            $paymentMethodUpdateData = $processor->updateCardFromRequest(PaymentMethod::from([
                ...$paymentMethod->toArray(),
                'app' => $app,
                'user' => $user,
                'company' => $company,
            ]), $input);
        } else {
            $paymentMethodUpdateData = new PaymentMethod(
                app: $app,
                user: $user,
                company: $company,
                instrument_identifier_id: $input['instrument_identifier_id'] ?? $paymentMethod->instrument_identifier_id,
                payment_ending_numbers: $input['number'] ?? $paymentMethod->payment_ending_numbers,
                payment_methods_brand: $input['brand'] ?? $paymentMethod->payment_methods_brand,
                stripe_card_id: $input['stripe_card_id'] ?? $paymentMethod->stripe_card_id,
                expiration_date: $input['expiration_date'] ?? $paymentMethod->expiration_date,
                zip_code: $input['zip_code'] ?? $paymentMethod->zip_code,
                processor: $input['processor'] ?? $paymentMethod->processor,
                metadata: $input['metadata'] ?? $paymentMethod->metadata
            );
        }

        return new UpdatePaymentMethodAction(
            $paymentMethod->id,
            $paymentMethodUpdateData
        )->execute();
    }

    public function deletePaymentMethod(mixed $root, array $request): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $paymentMethod = PaymentMethods::fromCompany($company)->fromApp($app)->where([
            'id' => $request['id'],
        ])->first();

        if (! $paymentMethod) {
            throw new ValidationException('Payment method not found');
        }

        if ($paymentMethod->processor) {
            $processor = app("payment.{$paymentMethod->processor}");
            $processor->deleteCardFromRequest(PaymentMethod::from([
                ...$paymentMethod->toArray(),
                'app' => $app,
                'user' => $user,
                'company' => $company,
            ]));
        }

        return $paymentMethod->delete();
    }

    public function guessCardBrand(string $number): ?string
    {
        $number = preg_replace('/[^0-9]/', '', $number);

        if (! $this->isValidLuhn($number)) {
            return null;
        }

        $firstDigit = substr($number, 0, 1);
        $firstTwoDigits = substr($number, 0, 2);

        // Visa
        if ($firstDigit === '4') {
            return 'visa';
        }

        // Mastercard
        if ($firstTwoDigits >= '51' && $firstTwoDigits <= '55') {
            return 'mastercard';
        }

        // American Express
        if ($firstTwoDigits === '34' || $firstTwoDigits === '37') {
            return 'american express';
        }

        return strtolower($this->getCardBrand($number));
    }

    public function getCardBrand(string $cardNumber): ?string
    {
        // Remove spaces and dashes
        $cardNumber = preg_replace('/[\s\-]/', '', $cardNumber);

        // Check card brand by prefix and length
        if (preg_match('/^4[0-9]{12}(?:[0-9]{3})?$/', $cardNumber)) {
            return 'Visa';
        } elseif (preg_match('/^5[1-5][0-9]{14}$/', $cardNumber)) {
            return 'Mastercard';
        } elseif (preg_match('/^3[47][0-9]{13}$/', $cardNumber)) {
            return 'American Express';
        }

        return null;
    }

    private function isValidLuhn(string|int $number): bool
    {
        $sum = 0;
        $length = strlen((string)$number);
        $parity = $length % 2;

        for ($i = 0; $i < $length; $i++) {
            $digit = (int)$number[$i];
            if ($i % 2 === $parity) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }

        return $sum % 10 === 0;
    }
}
