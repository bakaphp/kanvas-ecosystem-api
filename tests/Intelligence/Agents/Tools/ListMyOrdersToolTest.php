<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Intelligence\Agents\Neuron\Tools\Commerce\ListMyOrdersTool;
use Kanvas\Intelligence\Sessions\Models\Session;
use Tests\Intelligence\Agents\Concerns\CreatesShopperFixtures;
use Tests\TestCase;

final class ListMyOrdersToolTest extends TestCase
{
    use CreatesShopperFixtures;
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'crm'];

    public function testAnonymousShopperGetsNothingAndIsToldToSignIn(): void
    {
        $this->createOrder(['user_email' => 'buyer@example.com']);

        $result = $this->tool(null)->__invoke();

        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['orders']);
        $this->assertSame('not_signed_in', $result['reason']);
    }

    public function testListsOnlyTheShoppersOwnOrdersNewestFirst(): void
    {
        $shopper = $this->makeShopper();
        $someoneElse = $this->makeShopper();
        $older = $this->createOrder(['people_id' => $shopper->getId(), 'status' => 'completed']);
        $newer = $this->createOrder(['people_id' => $shopper->getId(), 'status' => 'pending']);
        $this->createOrder(['people_id' => $someoneElse->getId(), 'status' => 'pending']);

        $result = $this->tool($this->sessionFor($shopper))->__invoke();

        $this->assertSame(2, $result['count']);
        $this->assertSame(
            [$newer->order_number, $older->order_number],
            array_column($result['orders'], 'order_number')
        );
    }

    public function testOnlyOpenExcludesFinishedOrders(): void
    {
        $shopper = $this->makeShopper();
        $this->createOrder(['people_id' => $shopper->getId(), 'status' => 'completed']);
        $this->createOrder(['people_id' => $shopper->getId(), 'status' => 'canceled']);
        $this->createOrder(['people_id' => $shopper->getId(), 'status' => 'cancelled']);
        $open = $this->createOrder(['people_id' => $shopper->getId(), 'status' => 'pending']);

        $result = $this->tool($this->sessionFor($shopper))->__invoke(only_open: true);

        $this->assertSame([$open->order_number], array_column($result['orders'], 'order_number'));
    }

    public function testLimitIsClamped(): void
    {
        $shopper = $this->makeShopper();
        foreach (range(1, 3) as $_) {
            $this->createOrder(['people_id' => $shopper->getId(), 'status' => 'pending']);
        }

        $result = $this->tool($this->sessionFor($shopper))->__invoke(limit: 0);

        $this->assertSame(1, $result['count']);
    }

    private function tool(?Session $session): ListMyOrdersTool
    {
        return new ListMyOrdersTool($session)->withContext($this->apps, $this->company, $this->user);
    }
}
