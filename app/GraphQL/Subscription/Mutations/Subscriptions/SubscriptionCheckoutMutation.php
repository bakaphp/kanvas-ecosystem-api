<?php

declare(strict_types=1);

namespace App\GraphQL\Subscription\Mutations\Subscriptions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Subscription\Subscriptions\DataTransferObject\SubscriptionCheckoutInput;
use Throwable;

class SubscriptionCheckoutMutation
{
    /**
     * Generate a checkout session for a subscription
     */
    public function generate(mixed $root, array $args): array
    {
        $app = app(Apps::class);
        $user = auth()->user();

        if (empty($app->get(ConfigurationEnum::STRIPE_SECRET_KEY->value))) {
            throw new ValidationException('Stripe is not configured for this app');
        }

        $company = $user->getCurrentCompany();

        $input = SubscriptionCheckoutInput::viaRequest(
            $args['input'],
            $user,
            $company,
            $app
        );

        $companyStripeAccount = $company->getStripeAccount($app);

        // Ensure they are a Stripe customer first
        if (! $companyStripeAccount->hasStripeId()) {
            $companyStripeAccount->createOrGetStripeCustomer();
        }

        try {
            // To avoid version conflicts and ensure 'subscription' mode, use Stripe SDK directly via the model
            $stripe = $companyStripeAccount::stripe(['app_id' => $app->getId()]);
            
            $sessionData = [
                'customer' => $companyStripeAccount->stripe_id,
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price' => $input->price->stripe_id,
                    'quantity' => 1,
                ]],
                'mode' => 'subscription',
                'success_url' => $input->success_url ?? $app->get(ConfigurationEnum::CHECKOUT_SUCCESS_URL->value) ?? 'https://kanvas.dev/success',
                'cancel_url' => $input->cancel_url ?? $app->get(ConfigurationEnum::CHECKOUT_CANCEL_URL->value) ?? 'https://kanvas.dev/cancel',
                'metadata' => [
                    'kanvas_uuid' => $company->uuid,
                    'companies_id' => $company->getId(),
                    'apps_id' => $app->getId(),
                ],
            ];

            // Handle trial days if the plan has them
            if ($input->price->plan && $input->price->plan->free_trial_dates) {
                $sessionData['subscription_data'] = [
                    'trial_period_days' => $input->price->plan->free_trial_dates,
                ];
            }

            $session = $stripe->checkout->sessions->create($sessionData);

            return [
                'status' => 'success',
                'payment_url' => $session->url,
                'session_id' => $session->id,
                'message' => 'Checkout session created successfully',
            ];
            
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'payment_url' => null,
                'session_id' => null,
                'message' => $e->getMessage(),
            ];
        }
    }
}
