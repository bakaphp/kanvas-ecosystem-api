<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Baka\Support\Str;
use Kanvas\Connectors\Stripe\Services\StripePaymentService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Orders\Models\Order as ModelsOrder;
use Override;

class CreateOrderFromCartAction extends CreateBaseOrderAction
{
    private ?string $chargedIntentId = null;

    #[Override]
    public function execute(): ModelsOrder
    {
        $isFullDiscountCartAmount = $this->cart->getTotal() === 0 && $this->cart->getSessionKey() === ($this->request['input']['metadata']['paymentIntent']['id'] ?? null);
        $paymentIntentId = $this->request['input']['metadata']['paymentIntent']['id']
            ?? $this->request['input']['payment_intent_id']
            ?? $this->request['input']['metadata']['paymentIntent']['client_secret'] //remove later
            ?? null;

        /**
         * @todo remove later
         */
        if (Str::contains($paymentIntentId, '_secret_')) {
            $paymentIntentId = explode('_secret_', $paymentIntentId ?? '')[0]; // Gets "pi_3RAClYDdrFkcUBzl0vNHHnFD"
        }

        // The FE may have charged the intent even when the app does not require us to validate it.
        $this->chargedIntentId = ! empty($paymentIntentId) && ! $isFullDiscountCartAmount ? $paymentIntentId : null;

        if (! $this->app->get(ConfigurationEnum::ALLOW_NO_PAYMENT_ORDER->value) && ! $isFullDiscountCartAmount) {
            if (! $paymentIntentId) {
                throw new ValidationException('Payment Intent not provided');
            }
            $stripe = new StripePaymentService($this->app);
            $validation = $stripe->validatePaymentIntent($paymentIntentId);

            if (! $validation['valid']) {
                throw new ValidationException($validation['error'], $validation['status']);
            }
        }

        $recovered = $this->chargedIntentId !== null
            ? ModelsOrder::fromApp($this->app)->where('payment_intent_id', $this->chargedIntentId)->first()
            : null;

        if ($recovered !== null) {
            $this->cart->clear();

            return $recovered;
        }

        return parent::execute();
    }

    #[Override]
    protected function chargedPaymentIntentId(): ?string
    {
        return $this->chargedIntentId;
    }

    #[Override]
    protected function isPrepaidAtCheckout(ModelsOrder $order): bool
    {
        return $this->chargedIntentId !== null || parent::isPrepaidAtCheckout($order);
    }
}
