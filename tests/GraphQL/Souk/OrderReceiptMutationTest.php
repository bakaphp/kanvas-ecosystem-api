<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\DataTransferObject\OrderReceipt;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class OrderReceiptMutationTest extends TestCase
{
    private const string MUTATION = '
        mutation generateOrderReceipt($id: ID!) {
            generateOrderReceipt(id: $id) {
                id
                url
            }
        }
    ';

    public function testRejectsOrdersWhoseTypeHasNoReceiptTemplate(): void
    {
        $order = $this->makeOrder();
        $order->setOrderType('no-receipt-' . uniqid());

        $this->graphQL(self::MUTATION, ['id' => $order->getId()])
            ->assertGraphQLErrorMessage('Order type ' . $order->refresh()->orderType->name . ' has no PDF receipt template configured');
    }

    public function testAsksToRetryWhenTheReceiptCannotBeRendered(): void
    {
        $order = $this->makeOrder();
        $order->setOrderType('broken-receipt-' . uniqid());
        $order->orderType->config = [OrderReceipt::CONFIG_KEY => ['template' => 'missing-template-' . uniqid()]];
        $order->orderType->save();

        $this->graphQL(self::MUTATION, ['id' => $order->getId()])
            ->assertGraphQLErrorMessage('The receipt PDF could not be generated, please try again');

        $this->assertNull($order->refresh()->get('receipt_url'));
    }

    private function makeOrder(): Order
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $people = People::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        return Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withPeopleId($people->getId())
            ->create();
    }
}
