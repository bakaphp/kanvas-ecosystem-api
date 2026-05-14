<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Services;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Enums\CustomFieldEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Payments\DataTransferObjet\PaymentMethod as PaymentMethodData;
use Kanvas\Souk\Payments\Contracts\TokenizationProcessorInterface;
use Kanvas\Souk\Payments\DataTransferObject\TokenizeResult;
use Override;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;

final class StripeTokenizationService implements TokenizationProcessorInterface
{
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly StripeClient $stripe,
    ) {
    }

    /**
     * Frontend-tokenized flow (Stripe.js / Elements):
     *   1. Browser exchanges raw card data for `pm_xxx` directly with Stripe — backend never sees the PAN.
     *   2. Caller posts that `pm_xxx` as `stripe_payment_method_id`.
     *   3. We attach the PaymentMethod to the user's Stripe Customer and return the token + card metadata.
     *
     * Persistence of the local PaymentMethods row is handled by PaymentMethodMutation::createPaymentMethod;
     * keep this method side-effect free at the DB layer to match Azul/Cardnet processors.
     */
    #[Override]
    public function tokenize(array $cardDetails, UserInterface $user): TokenizeResult
    {
        $pmId = (string) ($cardDetails['stripe_payment_method_id'] ?? '');

        if (empty($pmId)) {
            throw new ValidationException('stripe_payment_method_id is required for Stripe tokenization.');
        }

        $customerId = $this->upsertStripeCustomer($user);

        try {
            $this->stripe->paymentMethods->attach($pmId, ['customer' => $customerId]);
        } catch (InvalidRequestException $e) {
            // Already attached to this customer is fine; surface anything else (e.g. attached to a different customer).
            if (! str_contains((string) $e->getMessage(), 'already been attached')) {
                throw new ValidationException($e->getMessage());
            }
        } catch (ApiErrorException $e) {
            throw new ValidationException($e->getMessage());
        }

        $pm = $this->stripe->paymentMethods->retrieve($pmId);

        $last4 = (string) ($pm->card->last4 ?? '');
        $brand = (string) ($pm->card->brand ?? '');

        return new TokenizeResult(
            success: true,
            message: 'Card tokenized successfully.',
            token: $pmId,
            lastFour: $last4,
            brand: $brand,
            raw: [
                'stripe_customer_id' => $customerId,
                'stripe_payment_method_id' => $pmId,
            ],
        );
    }

    /**
     * Sync billing details to the existing Stripe PaymentMethod and return a fresh DTO
     * for UpdatePaymentMethodAction to persist locally. Stripe does not allow rotating
     * the PAN/expiration on an existing PaymentMethod — to swap cards, delete and create
     * a new PaymentMethod row.
     */
    public function updateToken(PaymentMethodData $paymentMethod, array $request): PaymentMethodData
    {
        $token = (string) ($paymentMethod->stripe_card_id ?? '');
        $existingMetadata = is_array($paymentMethod->metadata) ? $paymentMethod->metadata : [];

        $address = array_filter([
            'line1' => $request['address'] ?? null,
            'city' => $request['city'] ?? null,
            'state' => $request['state'] ?? null,
            'postal_code' => $request['zip_code'] ?? null,
            'country' => $request['country'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $name = trim(($request['firstname'] ?? '') . ' ' . ($request['lastname'] ?? ''));

        $billingDetails = array_filter([
            'name' => $name !== '' ? $name : null,
            'phone' => $request['phone'] ?? null,
            'address' => ! empty($address) ? $address : null,
        ], fn ($v) => $v !== null);

        if (! empty($token) && ! empty($billingDetails)) {
            try {
                $this->stripe->paymentMethods->update($token, ['billing_details' => $billingDetails]);
            } catch (ApiErrorException $e) {
                Log::warning('Stripe paymentMethods.update failed; continuing with local update', [
                    'token' => $token,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return new PaymentMethodData(
            app: $paymentMethod->app,
            user: $paymentMethod->user,
            company: $paymentMethod->company,
            payment_ending_numbers: $paymentMethod->payment_ending_numbers,
            payment_methods_brand: $request['brand'] ?? $paymentMethod->payment_methods_brand,
            expiration_date: $request['expiration_date'] ?? $paymentMethod->expiration_date,
            zip_code: $request['zip_code'] ?? $paymentMethod->zip_code,
            stripe_card_id: $token,
            instrument_identifier_id: $paymentMethod->instrument_identifier_id,
            processor: 'stripe',
            metadata: array_merge($existingMetadata, array_filter([
                'country' => $request['country'] ?? null,
                'city' => $request['city'] ?? null,
                'address' => $request['address'] ?? null,
                'phone' => $request['phone'] ?? null,
                'zip_code' => $request['zip_code'] ?? null,
                'state' => $request['state'] ?? null,
                'firstname' => $request['firstname'] ?? null,
                'lastname' => $request['lastname'] ?? null,
            ], fn ($v) => $v !== null)),
        );
    }

    #[Override]
    public function deleteToken(string $token): bool
    {
        try {
            $this->stripe->paymentMethods->detach($token);

            return true;
        } catch (InvalidRequestException) {
            // Already detached at Stripe — treat as success so the local soft-delete proceeds.
            return true;
        } catch (ApiErrorException $e) {
            Log::warning('Stripe paymentMethods.detach failed', [
                'token' => $token,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function upsertStripeCustomer(UserInterface $user): string
    {
        $compositeKey = CustomFieldEnum::STRIPE_CUSTOMER_ID->value
            . '-' . $this->app->getId()
            . '-' . $this->company->getId();

        $existingId = (string) ($user->get($compositeKey) ?? '');

        if (! empty($existingId)) {
            try {
                $this->stripe->customers->retrieve($existingId);

                return $existingId;
            } catch (InvalidRequestException) {
                // Customer was deleted at Stripe — fall through to create a new one.
            }
        }

        $displayName = trim(
            (property_exists($user, 'firstname') ? ($user->firstname ?? '') : '')
            . ' '
            . (property_exists($user, 'lastname') ? ($user->lastname ?? '') : '')
        );

        if (empty($displayName) && property_exists($user, 'displayname')) {
            $displayName = (string) ($user->displayname ?? '');
        }

        $customer = $this->stripe->customers->create([
            'email' => $user->email,
            'name' => $displayName,
            'metadata' => [
                'kanvas_app_id' => $this->app->getId(),
                'kanvas_company_id' => $this->company->getId(),
                'kanvas_user_id' => $user->getId(),
            ],
        ]);

        $user->set($compositeKey, $customer->id);

        return (string) $customer->id;
    }
}
