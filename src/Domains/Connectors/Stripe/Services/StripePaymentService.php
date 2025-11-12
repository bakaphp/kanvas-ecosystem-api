<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Services;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum as EnumsConfigurationEnum;
use Kanvas\Connectors\Stripe\Utils\StripePaymentValidation;
use Kanvas\Exceptions\ValidationException;
use Stripe\StripeClient;
use Throwable;

class StripePaymentService
{
    protected StripeClient $stripe;

    public function __construct(
        protected AppInterface $app,
    ) {
        $this->stripe = new StripeClient($app->get(EnumsConfigurationEnum::STRIPE_SECRET_KEY->value));
    }

    public function getClient(): StripeClient
    {
        return $this->stripe;
    }

    public function validatePaymentIntent(string $paymentIntentId, ?string $paymentMethodId = null): array
    {
        $params = [];

        try {
            $intent = $this->stripe->paymentIntents->retrieve($paymentIntentId);

            if ($paymentMethodId) {
                $params['payment_method'] = $paymentMethodId;
            }

            $statusValidation = $this->validatePaymentStatus($intent->status);

            if (! $statusValidation['can_process']) {
                return [
                    'valid' => false,
                    'status' => $intent->status,
                    'error' => $statusValidation['message'],
                    'payment_intent' => $intent,
                ];
            }

            return [
                'valid' => true,
                'status' => $intent->status,
                'payment_intent' => $intent,
            ];
        } catch (Throwable $e) {
            throw new ValidationException($e->getMessage());
        }
    }

    private function validatePaymentStatus(string $status): array
    {
        $validator = new StripePaymentValidation();

        if ($validator->isValidPayment($status)) {
            return [
                'can_process' => true,
                'message' => 'Payment is valid and can be processed',
            ];
        }

        return [
            'can_process' => false,
            'message' => $validator->getStatusMessage($status),
        ];
    }

    public function processPaymentIntent(string $paymentIntentId, ?string $paymentMethodId = null): void
    {
        if (Str::contains($paymentIntentId, '_secret_')) {
            $paymentIntentId = explode('_secret_', $paymentIntentId ?? '')[0]; // Gets "pi_3RAClYDdrFkcUBzl0vNHHnFD"
        }

        if (! $paymentIntentId) {
            throw new ValidationException('Payment Intent not provided');
        }

        $validation = $this->validatePaymentIntent($paymentIntentId, $paymentMethodId);

        if (! $validation['valid']) {
            throw new ValidationException($validation['error'], $validation['status']);
        }
    }
}
