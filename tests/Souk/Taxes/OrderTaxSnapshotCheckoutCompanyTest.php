<?php

declare(strict_types=1);

namespace Tests\Souk\Taxes;

use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use Tests\TestCase;

final class OrderTaxSnapshotCheckoutCompanyTest extends TestCase
{
    public function testAnUnattachedSnapshotResolvesTheCompanyFromItsCheckoutPayload(): void
    {
        $company = static::$cachedUser->getCurrentCompany();

        $snapshot = new OrderTaxSnapshot([
            'checkout_payload' => ['order_context' => ['companies_id' => $company->getId()]],
        ]);

        $this->assertTrue($snapshot->checkoutCompany()?->is($company));
    }

    public function testAnAttachedSnapshotUsesItsOrderCompany(): void
    {
        $company = static::$cachedUser->getCurrentCompany();

        $order = new Order();
        $order->setRelation('company', $company);

        $snapshot = new OrderTaxSnapshot([
            'checkout_payload' => ['order_context' => ['companies_id' => 999999999]],
        ]);
        $snapshot->setRelation('order', $order);

        $this->assertTrue($snapshot->checkoutCompany()?->is($company));
    }

    public function testASnapshotWithNoCompanyAnywhereFallsBackToTheAppAccount(): void
    {
        $this->assertNull(new OrderTaxSnapshot(['checkout_payload' => []])->checkoutCompany());
    }
}
