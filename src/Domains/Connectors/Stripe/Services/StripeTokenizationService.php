<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Services;

use Baka\Users\Contracts\UserInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Enums\CustomFieldEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Payments\Actions\CreatePaymentMethodAction;
use Kanvas\Payments\DataTransferObjet\PaymentMethod as PaymentMethodData;
use Kanvas\Payments\Models\PaymentMethods;
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

    #[Override]
    public function tokenize(array $cardDetails, UserInterface $user): TokenizeResult
    {
        $pmId = $cardDetails['stripe_payment_method_id'] ?? '';

        if (empty($pmId)) {
            throw new ValidationException('stripe_payment_method_id is required for Stripe tokenization.');
        }

        $existing = PaymentMethods::where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('users_id', $user->getId())
            ->where('processor', 'stripe')
            ->where('is_deleted', 0)
            ->whereJsonContains('metadata->stripe_payment_method_id', $pmId)
            ->first();

        if ($existing !== null) {
            return new TokenizeResult(
                success: true,
                message: 'Card already tokenized.',
                token: $pmId,
                lastFour: (string) $existing->payment_ending_numbers,
                brand: (string) $existing->payment_methods_brand,
                raw: (array) ($existing->metadata ?? []),
            );
        }

        $customerId = $this->upsertStripeCustomer($user);

        $pm = $this->stripe->paymentMethods->retrieve($pmId);

        try {
            $this->stripe->paymentMethods->attach($pmId, ['customer' => $customerId]);
        } catch (ApiErrorException $e) {
            throw new ValidationException($e->getMessage());
        }

        $last4 = (string) ($pm->card->last4 ?? '');
        $brand = (string) ($pm->card->brand ?? '');
        $expYear = (int) ($pm->card->exp_year ?? date('Y'));
        $expMonth = (int) ($pm->card->exp_month ?? 1);

        $paymentMethodRow = DB::connection('ecosystem')->transaction(
            function () use ($user, $pmId, $customerId, $last4, $brand, $expYear, $expMonth): PaymentMethods {
                return new CreatePaymentMethodAction(
                    new PaymentMethodData(
                        app: $this->app,
                        user: $user,
                        company: $this->company,
                        payment_ending_numbers: $last4,
                        payment_methods_brand: $brand,
                        expiration_date: Carbon::create($expYear, $expMonth, 1)->endOfMonth()->format('Y-m-d'),
                        zip_code: '',
                        stripe_card_id: $pmId,
                        processor: 'stripe',
                        metadata: [
                            'stripe_customer_id' => $customerId,
                            'stripe_payment_method_id' => $pmId,
                        ],
                    ),
                )->execute();
            }
        );

        return new TokenizeResult(
            success: true,
            message: 'Card tokenized successfully.',
            token: $pmId,
            lastFour: $last4,
            brand: $brand,
            raw: [
                'payment_method_id' => $paymentMethodRow->id,
                'stripe_customer_id' => $customerId,
                'stripe_payment_method_id' => $pmId,
            ],
        );
    }

    #[Override]
    public function deleteToken(string $token): bool
    {
        try {
            $this->stripe->paymentMethods->detach($token);
        } catch (InvalidRequestException) {
            // Already detached at Stripe — proceed with local soft-delete.
        } catch (ApiErrorException $e) {
            Log::warning('Stripe detach failed; continuing with local soft-delete', [
                'token' => $token,
                'error' => $e->getMessage(),
            ]);
        }

        PaymentMethods::where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('processor', 'stripe')
            ->where('is_deleted', 0)
            ->whereJsonContains('metadata->stripe_payment_method_id', $token)
            ->update(['is_deleted' => 1]);

        return true;
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
