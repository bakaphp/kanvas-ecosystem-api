<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Orders;

use Exception;
use GraphQL\Type\Definition\ResolveInfo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Stripe\Services\StripeCheckoutService;
use Kanvas\Connectors\Stripe\Services\StripePaymentLinkService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class PaymentLinkMutation
{
    /**
     * Generate a payment link for an order
     */
    public function generatePaymentLink(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): array {
        $user = auth()->user();
        $app = app(Apps::class);

        // Find the order
        $order = Order::fromCompany($user->getCurrentCompany())
            ->fromApp($app)
            ->where('id', $args['order_id'])
            ->firstOrFail();

        // Check permissions
        if (! $user->isAppOwner() && $order->users_id !== $user->getId()) {
            throw new ValidationException('You do not have permission to generate payment links for this order');
        }

        try {
            $options = $this->prepareOptions($args['options'] ?? []);
            $provider = $options['provider'] ?? 'stripe';

            $result = $this->generatePaymentLinkByProvider($order, $provider, $options);
            $result['provider'] = $provider;

            return $result;
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'payment_url' => null,
                'payment_link_id' => null,
                'session_id' => null,
                'provider' => $options['provider'] ?? 'stripe',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate a checkout session for an order
     */
    public function generateCheckoutSession(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): array {
        $user = auth()->user();
        $app = app(Apps::class);

        // Find the order
        $order = Order::fromCompany($user->getCurrentCompany())
            ->fromApp($app)
            ->where('id', $args['order_id'])
            ->firstOrFail();

        // Check permissions
        if (! $user->isAppOwner() && $order->users_id !== $user->getId()) {
            throw new ValidationException('You do not have permission to generate checkout sessions for this order');
        }

        try {
            $options = $this->prepareOptions($args['options'] ?? []);
            $provider = $options['provider'] ?? 'stripe';

            $result = $this->generateCheckoutSessionByProvider($order, $provider, $options);
            $result['provider'] = $provider;

            return $result;
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'payment_url' => null,
                'payment_link_id' => null,
                'session_id' => null,
                'provider' => $options['provider'] ?? 'stripe',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate payment link by provider
     */
    protected function generatePaymentLinkByProvider(Order $order, string $provider, array $options): array
    {
        switch ($provider) {
            case 'stripe':
                return $this->generateStripePaymentLink($order, $options);
            default:
                throw new ValidationException("Unsupported payment provider: {$provider}");
        }
    }

    /**
     * Generate checkout session by provider
     */
    protected function generateCheckoutSessionByProvider(Order $order, string $provider, array $options): array
    {
        switch ($provider) {
            case 'stripe':
                return $this->generateStripeCheckoutSession($order, $options);
            default:
                throw new ValidationException("Unsupported payment provider: {$provider}");
        }
    }

    /**
     * Generate Stripe payment link
     */
    protected function generateStripePaymentLink(Order $order, array $options): array
    {
        $app = app(Apps::class);
        $paymentLinkService = new StripePaymentLinkService($app);

        $paymentLink = $paymentLinkService->generatePaymentLink($order, $options);

        return [
            'status' => 'success',
            'payment_url' => $paymentLink->url,
            'payment_link_id' => $paymentLink->id,
            'session_id' => null,
            'message' => 'Payment link generated successfully',
        ];
    }

    /**
     * Generate Stripe checkout session
     */
    protected function generateStripeCheckoutSession(Order $order, array $options): array
    {
        $app = app(Apps::class);
        $paymentService = new StripeCheckoutService($app);

        // Choose between simple or detailed checkout based on options
        if ($options['use_detailed_items'] ?? false) {
            $session = $paymentService->createDetailedCheckoutSession($order, $options);
        } else {
            $session = $paymentService->createCheckoutSession($order, $options);
        }

        return [
            'status' => 'success',
            'payment_url' => $session->url,
            'payment_link_id' => null,
            'session_id' => $session->id,
            'message' => 'Checkout session created successfully',
        ];
    }

    /**
     * Prepare options for payment services
     */
    protected function prepareOptions(array $options): array
    {
        $prepared = [];

        // Handle success/cancel URLs
        if (isset($options['success_url'])) {
            $prepared['success_url'] = $options['success_url'];
        }

        if (isset($options['cancel_url'])) {
            $prepared['cancel_url'] = $options['cancel_url'];
        }

        // Handle automatic tax
        if (isset($options['automatic_tax'])) {
            $prepared['automatic_tax'] = $options['automatic_tax'];
        }

        // Handle allowed countries for shipping
        if (isset($options['allowed_countries'])) {
            $prepared['allowed_countries'] = $options['allowed_countries'];
        }

        // Handle payment method types
        if (isset($options['payment_method_types'])) {
            $prepared['payment_method_types'] = $options['payment_method_types'];
        }

        // Handle provider
        if (isset($options['provider'])) {
            $prepared['provider'] = $options['provider'];
        }

        // Handle custom fields
        if (isset($options['custom_fields'])) {
            $prepared['custom_fields'] = $this->prepareCustomFields($options['custom_fields']);
        }

        $prepared['use_detailed_items'] = $options['use_detailed_items'] ?? true;

        return $prepared;
    }

    /**
     * Prepare custom fields for payment providers
     */
    protected function prepareCustomFields(array $customFields): array
    {
        $prepared = [];

        foreach ($customFields as $field) {
            $paymentField = [
                'key' => $field['key'],
                'type' => $field['type'],
                'label' => [
                    'type' => 'custom',
                    'custom' => $field['label'],
                ],
                'optional' => $field['optional'] ?? true,
            ];

            // Add dropdown options if type is dropdown
            if ($field['type'] === 'dropdown' && isset($field['dropdown_options'])) {
                $paymentField['dropdown'] = [
                    'options' => array_map(function ($option) {
                        return [
                            'label' => $option,
                            'value' => $option,
                        ];
                    }, $field['dropdown_options']),
                ];
            }

            $prepared[] = $paymentField;
        }

        return $prepared;
    }
}
