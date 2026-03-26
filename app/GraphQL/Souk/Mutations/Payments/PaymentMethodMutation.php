<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Payments;

use Exception;
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
            if ($input['processor']) {
                $input['brand'] = $this->guessCardBrand($input['number']);
                $processor = app("payment.{$input['processor']}");
                $result = $processor->tokenize($input, $user);

                if (! $result->success) {
                    throw new ValidationException($result->message);
                }

                $paymentMethod = new PaymentMethod(
                    app: $app,
                    user: $user,
                    company: $company,
                    payment_ending_numbers: $result->lastFour,
                    payment_methods_brand: $result->brand,
                    expiration_date: $input['expiration_date'],
                    zip_code: $input['zip_code'] ?? '',
                    stripe_card_id: $result->token,
                    instrument_identifier_id: $result->raw['instrumentIdentifierId'] ?? null,
                    processor: $input['processor'],
                    metadata: array_merge($result->raw, [
                        'country'   => $input['country'] ?? null,
                        'city'      => $input['city'] ?? null,
                        'address'   => $input['address'] ?? null,
                        'phone'     => $input['phone'] ?? null,
                        'zip_code'  => $input['zip_code'] ?? null,
                        'state'     => $input['state'] ?? null,
                        'firstname' => $input['firstname'] ?? null,
                        'lastname'  => $input['lastname'] ?? null,
                    ]),
                );
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
                        'country'   => $input['country'],
                        'city'      => $input['city'],
                        'address'   => $input['address'],
                        'phone'     => $input['phone'],
                        'zip_code'  => $input['zip_code'],
                        'state'     => $input['state'],
                        'firstname' => $input['firstname'] ?? null,
                        'lastname'  => $input['lastname'] ?? null,
                    ],
                );
            }

            return new CreatePaymentMethodAction($paymentMethod)->execute();
        } catch (RequestException $e) {
            $errorMessage = 'An error occurred while processing your request try again';

            if ($e->hasResponse()) {
                $statusCode = $e->getResponse()->getStatusCode();

                // Report 5xx server errors
                if ($statusCode >= 500) {
                    report($e);
                }

                try {
                    $decoded = json_decode((string) $e->getResponse()->getBody());
                    $errorMessage = $decoded?->message ?? $errorMessage;
                } catch (Exception $e) {
                    // If JSON parsing fails, use default message
                }
            } else {
                $errorMessage = $e->getMessage();
                // Also report if we can't get response details
                report($e);
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
            $paymentMethodUpdateData = $processor->updateToken(PaymentMethod::from([
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

        if ($paymentMethod->processor && $paymentMethod->stripe_card_id) {
            $processor = app("payment.{$paymentMethod->processor}");
            $processor->deleteToken($paymentMethod->stripe_card_id);
        }

        return $paymentMethod->delete();
    }

    public function guessCardBrand(string $number): ?string
    {
        $number = preg_replace('/[^0-9]/', '', $number);

        if (! $this->isValidLuhn($number)) {
            throw new ValidationException('The card number you entered is invalid. Please check and try again');
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

        // Discover
        $firstFourDigits = substr($number, 0, 4);
        $firstThreeDigits = substr($number, 0, 3);
        if ($firstFourDigits === '6011' || $firstTwoDigits === '65'
            || ($firstThreeDigits >= '644' && $firstThreeDigits <= '649')) {
            return 'discover';
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
