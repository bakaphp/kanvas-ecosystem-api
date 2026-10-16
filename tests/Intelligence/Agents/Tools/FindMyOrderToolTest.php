<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Intelligence\Agents\Neuron\Tools\Commerce\FindMyOrderTool;
use Kanvas\Intelligence\Sessions\Models\Session;
use Tests\Intelligence\Agents\Concerns\CreatesShopperFixtures;
use Tests\TestCase;

final class FindMyOrderToolTest extends TestCase
{
    use CreatesShopperFixtures;
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'crm'];

    public function testSignedInShopperFindsTheirOwnOrderByNumberAlone(): void
    {
        $shopper = $this->makeShopper();
        $order = $this->createOrder(['people_id' => $shopper->getId(), 'status' => 'pending']);

        $result = $this->tool($this->sessionFor($shopper))->__invoke(order_number: (string) $order->order_number);

        $this->assertTrue($result['found']);
        $this->assertSame($order->order_number, $result['order_number']);
        $this->assertSame('pending', $result['status']);
        $this->assertArrayHasKey('items', $result);
        $this->assertArrayNotHasKey('customer_email', $result);
        $this->assertArrayNotHasKey('affiliate_commissions', $result);
    }

    public function testSignedInShopperCannotSeeAnotherShoppersOrder(): void
    {
        $shopper = $this->makeShopper();
        $someoneElse = $this->makeShopper();
        $order = $this->createOrder(['people_id' => $someoneElse->getId()]);

        $result = $this->tool($this->sessionFor($shopper))->__invoke(order_number: (string) $order->order_number);

        $this->assertFalse($result['found']);
    }

    public function testAnonymousShopperMustSupplyTheOrderEmail(): void
    {
        $order = $this->createOrder(['user_email' => 'buyer@example.com']);

        $result = $this->tool(null)->__invoke(order_number: (string) $order->order_number);

        $this->assertFalse($result['found']);
        $this->assertSame('email_required', $result['reason']);
    }

    public function testAnonymousShopperFindsTheOrderOnlyWhenNumberAndEmailBothMatch(): void
    {
        $email = 'buyer' . uniqid() . '@example.com';
        $order = $this->createOrder(['user_email' => $email]);

        $match = $this->tool(null)->__invoke(
            order_number: (string) $order->order_number,
            email: strtoupper($email),
        );
        $wrongEmail = $this->tool(null)->__invoke(
            order_number: (string) $order->order_number,
            email: 'someone-else@example.com',
        );

        $this->assertTrue($match['found']);
        $this->assertFalse($wrongEmail['found']);
        $this->assertArrayNotHasKey('reason', $wrongEmail);
    }

    public function testFailsClosedWithoutTenantContext(): void
    {
        $result = new FindMyOrderTool()->__invoke(order_number: '123');

        $this->assertSame('no_tenant_context', $result['reason']);
    }

    private function tool(?Session $session): FindMyOrderTool
    {
        return new FindMyOrderTool($session)->withContext($this->apps, $this->company, $this->user);
    }
}
