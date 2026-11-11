<?php

declare(strict_types=1);

namespace Tests\Souk\Taxes;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Taxes\Enums\ConfigurationEnum;
use Kanvas\Souk\Taxes\Enums\TaxConditionEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Wearepixel\Cart\Cart;

#[Group('serial')]
final class RecalculateCartTaxListenerTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'ecosystem'];

    private Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->kanvasApp->set(ConfigurationEnum::TAX_ENGINE_ENABLED->value, 1);
        $this->kanvasApp->set(ConfigurationEnum::TAX_FAIL_OPEN->value, 1);
    }

    protected function tearDown(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::TAX_ENGINE_ENABLED->value, 0);
        $this->kanvasApp->set(ConfigurationEnum::TAX_FAIL_OPEN->value, 0);

        parent::tearDown();
    }

    public function testAddingAnItemWritesTheTaxCondition(): void
    {
        $cart = $this->makeCart();

        $this->addItem($cart);

        $this->assertNotNull($cart->getCondition(TaxConditionEnum::NAME->value));
    }

    public function testUpdatingAnItemRecalculatesTheTaxCondition(): void
    {
        $cart = $this->makeCart();
        $this->addItem($cart);
        $cart->removeCartCondition(TaxConditionEnum::NAME->value);

        $cart->update(99999, ['quantity' => 1]);

        $this->assertNotNull($cart->getCondition(TaxConditionEnum::NAME->value));
    }

    public function testRemovingTheLastItemClearsTheTaxCondition(): void
    {
        $cart = $this->makeCart();
        $this->addItem($cart);

        $cart->remove(99999);

        $this->assertNull($cart->getCondition(TaxConditionEnum::NAME->value));
    }

    public function testNothingIsWrittenWhileTheTaxEngineIsOff(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::TAX_ENGINE_ENABLED->value, 0);
        $cart = $this->makeCart();

        $this->addItem($cart);

        $this->assertNull($cart->getCondition(TaxConditionEnum::NAME->value));
    }

    private function makeCart(): Cart
    {
        $cart = app('cart')->session('listener-session-' . uniqid());
        $cart->clear();
        $cart->clearAllConditions();

        return $cart;
    }

    private function addItem(Cart $cart): void
    {
        $cart->add([
            'id' => 99999,
            'name' => 'Test Product',
            'price' => 100.00,
            'quantity' => 1,
            'attributes' => [],
        ]);
    }
}
