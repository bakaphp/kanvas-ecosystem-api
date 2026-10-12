<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\AppEnums;
use Kanvas\Intelligence\Agents\Neuron\Tools\Commerce\AddToCartTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Commerce\RemoveFromCartTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Commerce\UpdateCartItemTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Commerce\ViewCartTool;
use Kanvas\Regions\Models\Regions;
use Kanvas\Users\Models\Users;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

final class ShopperCartToolsTest extends TestCase
{
    use InventoryCases;

    private Apps $apps;
    private Users $user;
    private Companies $company;
    private string $identifierKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apps = app(Apps::class);
        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();
        $this->identifierKey = AppEnums::KANVAS_IDENTIFIER->getValue();

        app()->forgetInstance($this->identifierKey);
    }

    protected function tearDown(): void
    {
        if (app()->bound($this->identifierKey)) {
            app('cart')->session((string) app($this->identifierKey))->clear();
            app()->forgetInstance($this->identifierKey);
        }

        parent::tearDown();
    }

    public function testEveryCartToolRefusesWhenTheTurnDidNotComeFromTheStore(): void
    {
        foreach ([
            new ViewCartTool()->__invoke(),
            $this->withContext(new AddToCartTool())->__invoke(variant_id: 1, quantity: 1),
            new UpdateCartItemTool()->__invoke(variant_id: 1, quantity: 2),
            new RemoveFromCartTool()->__invoke(variant_id: 1),
        ] as $result) {
            $this->assertFalse($result['success']);
            $this->assertSame('cart_unavailable', $result['reason']);
        }
    }

    public function testAddViewUpdateAndRemoveRoundTripOnTheStorefrontCart(): void
    {
        $variantId = $this->createPricedVariantId();
        $this->bindStorefrontIdentifier();

        $this->assertTrue(new ViewCartTool()->__invoke()['is_empty']);

        $added = $this->withContext(new AddToCartTool())->__invoke(variant_id: $variantId, quantity: 2);

        $this->assertTrue($added['success']);
        $this->assertCount(1, $added['items']);
        $this->assertSame($variantId, $added['items'][0]['variant_id']);
        $this->assertSame(2.0, $added['items'][0]['quantity']);
        $this->assertSame(
            $added['items'][0]['unit_price'] * 2,
            $added['items'][0]['line_total']
        );
        $this->assertArrayHasKey('total', $added);

        $updated = new UpdateCartItemTool()->__invoke(variant_id: $variantId, quantity: 5);

        $this->assertTrue($updated['success']);
        $this->assertSame(5.0, $updated['items'][0]['quantity']);

        $viewed = new ViewCartTool()->__invoke();

        $this->assertSame(5.0, $viewed['items'][0]['quantity']);

        $removed = new RemoveFromCartTool()->__invoke(variant_id: $variantId);

        $this->assertTrue($removed['success']);
        $this->assertTrue($removed['is_empty']);
    }

    public function testAUserIdFallbackIdentifierIsNeverTreatedAsAShopperCart(): void
    {
        app()->instance($this->identifierKey, $this->user->getId());

        $result = new ViewCartTool()->__invoke();

        $this->assertFalse($result['success']);
        $this->assertSame('cart_unavailable', $result['reason']);
    }

    public function testTheAgentAndTheStorefrontReadAndWriteTheSameCart(): void
    {
        $storefrontVariantId = $this->createPricedVariantId();
        $agentVariantId = $this->createPricedVariantId();
        $identifier = (string) Str::uuid();
        $headers = [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-Identifier' => $identifier,
        ];

        $this->graphQL(
            'mutation($items: [CartItemInput!]!) { addToCart(items: $items) { id quantity } }',
            ['items' => [['variant_id' => $storefrontVariantId, 'quantity' => 1]]],
            [],
            $headers,
        )->assertSuccessful();

        app()->forgetInstance($this->identifierKey);
        app()->instance($this->identifierKey, $identifier);

        $seenByAgent = collect(new ViewCartTool()->__invoke()['items'])->pluck('variant_id');

        $this->assertContains($storefrontVariantId, $seenByAgent);

        $this->withContext(new AddToCartTool())->__invoke(variant_id: $agentVariantId, quantity: 2);

        $storefrontCart = $this->graphQL(
            'query { cart { items { id quantity } } }',
            [],
            [],
            $headers,
        )->assertSuccessful()->json('data.cart.items');

        $this->assertEqualsCanonicalizing(
            [$storefrontVariantId, $agentVariantId],
            array_map('intval', array_column($storefrontCart, 'id'))
        );
    }

    public function testUpdateAndRemoveReportALineThatIsNotInTheCart(): void
    {
        $this->bindStorefrontIdentifier();

        $update = new UpdateCartItemTool()->__invoke(variant_id: 999999, quantity: 1);
        $remove = new RemoveFromCartTool()->__invoke(variant_id: 999999);

        $this->assertFalse($update['success']);
        $this->assertFalse($remove['success']);
        $this->assertStringContainsString('not in the cart', $update['message']);
        $this->assertStringContainsString('not in the cart', $remove['message']);
    }

    public function testAddRejectsAQuantityBelowOneAndAnUnknownVariant(): void
    {
        $this->bindStorefrontIdentifier();
        $tool = $this->withContext(new AddToCartTool());

        $zero = $tool->__invoke(variant_id: 1, quantity: 0);
        $unknown = $tool->__invoke(variant_id: 999999, quantity: 1);

        $this->assertFalse($zero['success']);
        $this->assertFalse($unknown['success']);
        $this->assertStringContainsString('Could not add', $unknown['error']);
        $this->assertSame('invalid_args', $zero['outcome'], 'A refused write names its outcome, not just an error key');
    }

    private function bindStorefrontIdentifier(): void
    {
        app()->instance($this->identifierKey, (string) Str::uuid());
    }

    private function withContext(AddToCartTool $tool): AddToCartTool
    {
        return $tool->withContext($this->apps, $this->company, $this->user);
    }

    private function createPricedVariantId(): int
    {
        $region = Regions::getDefault($this->company, $this->apps);
        $warehouse = $this->createWarehouses((string) $region->getId())->json()['data']['createWarehouse'];
        $product = $this->createProduct()->json()['data']['createProduct'];
        $variant = $this->createVariant(
            productId: $product['id'],
            warehouseData: ['id' => $warehouse['id']]
        )->json()['data']['createVariant'];

        $this->addVariantToWarehouse(
            variantId: $variant['id'],
            warehouseId: $warehouse['id'],
            amount: 10
        );

        return (int) $variant['id'];
    }
}
