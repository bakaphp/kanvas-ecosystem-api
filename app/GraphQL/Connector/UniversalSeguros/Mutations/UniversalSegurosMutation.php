<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\UniversalSeguros\Mutations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\UniversalSeguros\Actions\CreateQuoteAction;
use Kanvas\Connectors\UniversalSeguros\Actions\EmitPolicyAction;
use Kanvas\Connectors\UniversalSeguros\Actions\RequestPaymentLinkAction;
use Kanvas\Connectors\UniversalSeguros\Enums\ProductEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

class UniversalSegurosMutation
{
    public function createQuote(mixed $rootValue, array $request): array
    {
        $order = $this->order((int) $request['order_id']);
        $product = ProductEnum::from((string) $request['product']);
        /** @var array<string, mixed> $input */
        $input = (array) $request['input'];

        return new CreateQuoteAction($order, $product, $input)->execute();
    }

    public function requestPaymentLink(mixed $rootValue, array $request): array
    {
        $order = $this->order((int) $request['order_id']);

        return new RequestPaymentLinkAction($order, (bool) ($request['by_email'] ?? false))->execute();
    }

    public function emitPolicy(mixed $rootValue, array $request): array
    {
        $order = $this->order((int) $request['order_id']);

        return new EmitPolicyAction($order)->execute();
    }

    protected function order(int $id): Order
    {
        /** @var Users $user */
        $user = auth()->user();

        /** @var Order $order */
        $order = Order::getByIdFromCompanyApp($id, $user->getCurrentCompany(), app(Apps::class));

        return $order;
    }
}
