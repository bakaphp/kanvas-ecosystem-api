<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Insurance\Mutations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Insurance\Contracts\InsuranceProcessorInterface;
use Kanvas\Souk\Insurance\Processors\InsuranceProcessorFactory;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

/**
 * Provider-agnostic insurance mutations. New insurance providers register a binding in
 * InsuranceProcessorServiceProvider instead of adding new `{provider}CreateQuote`-style
 * mutations here — same pattern as PaymentProcessorMutation / ProcessorFactory.
 */
class InsuranceMutation
{
    public function createQuote(mixed $rootValue, array $request): array
    {
        $order = $this->order((int) $request['order_id']);
        /** @var array<string, mixed> $input */
        $input = (array) $request['input'];

        return $this->processor((string) $request['provider'], $order)
            ->createQuote($order, (string) $request['product'], $input);
    }

    public function requestPaymentLink(mixed $rootValue, array $request): array
    {
        $order = $this->order((int) $request['order_id']);

        return $this->processor((string) $request['provider'], $order)
            ->requestPaymentLink($order, (bool) ($request['by_email'] ?? false));
    }

    public function emitPolicy(mixed $rootValue, array $request): array
    {
        $order = $this->order((int) $request['order_id']);

        return $this->processor((string) $request['provider'], $order)
            ->emitPolicy($order);
    }

    protected function order(int $id): Order
    {
        /** @var Users $user */
        $user = auth()->user();

        /** @var Order $order */
        $order = Order::getByIdFromCompanyApp($id, $user->getCurrentCompany(), app(Apps::class));

        return $order;
    }

    protected function processor(string $provider, Order $order): InsuranceProcessorInterface
    {
        return InsuranceProcessorFactory::make($provider, $order->app, $order->company);
    }
}
