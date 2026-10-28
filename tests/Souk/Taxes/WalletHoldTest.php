<?php

declare(strict_types=1);

namespace Tests\Souk\Taxes;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Taxes\Actions\ReleaseCheckoutHoldsAction;
use Kanvas\Souk\Taxes\Enums\ConfigurationEnum;
use Kanvas\Souk\Taxes\Enums\GatewayAttemptStatusEnum;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use Kanvas\Souk\Wallet\Enums\ConfigurationEnum as WalletConfigurationEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Throwable;

#[Group('serial')]
final class WalletHoldTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'ecosystem'];

    private Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->kanvasApp->set(ConfigurationEnum::TAX_ENGINE_ENABLED->value, 1);
    }

    protected function tearDown(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::TAX_ENGINE_ENABLED->value, 0);

        parent::tearDown();
    }

    private function makeSnapshot(array $overrides = []): OrderTaxSnapshot
    {
        $user = auth()->user();

        $snapshot = new OrderTaxSnapshot();
        $snapshot->apps_id = $this->kanvasApp->getId();
        $snapshot->order_id = null;
        $snapshot->cart_session_id = 'wallet-test-' . uniqid();
        $snapshot->kind = 'calculation';
        $snapshot->treatment = 'taxed';
        $snapshot->status = 'calculated';
        $snapshot->provider = 'manual_rate';
        $snapshot->input_hash = hash('sha256', 'wallet-test-' . uniqid());
        $snapshot->tax_amount = 18.00;
        $snapshot->taxable_amount = 100.00;
        $snapshot->remaining_reversible_amount = 100.00;
        $snapshot->remaining_reversible_tax = 18.00;
        $snapshot->gateway_attempt_key = hash('sha256', uniqid());
        $snapshot->gateway_attempt_status = GatewayAttemptStatusEnum::PENDING->value;
        $snapshot->souk_tax_engine_enabled = true;
        $snapshot->checkout_payload = array_merge([
            'lines' => [
                [
                    'uuid' => 'line-uuid-1',
                    'variant_id' => 1,
                    'product_name' => 'Test Product',
                    'product_sku' => 'SKU-001',
                    'quantity' => 1,
                    'unit_price_net_amount' => 100.00,
                    'tax_amount' => 18.00,
                ],
            ],
            'shipping_address' => null,
            'billing_address' => null,
            'discount_allocation' => [],
            'wallet_hold_transfer_id' => null,
            'credit_reservation_id' => null,
            'order_context' => [
                'region_id' => 1,
                'people_id' => 0,
                'companies_id' => $user->getCurrentCompany()->getId(),
                'users_id' => $user->getId(),
            ],
            'totals' => [
                'subtotal' => 100.00,
                'discount' => 0.00,
                'tax' => 18.00,
                'shipping' => 0.00,
                'payable' => 118.00,
                'wallet_tender' => 0.00,
                'gateway_due' => 118.00,
            ],
        ], $overrides['checkout_payload'] ?? []);

        unset($overrides['checkout_payload']);

        foreach ($overrides as $key => $value) {
            $snapshot->{$key} = $value;
        }

        $snapshot->saveOrFail();

        return $snapshot;
    }

    private function getCompanyWallet(float $fund = 100.0): array
    {
        $user = auth()->user();
        $company = Companies::getById($user->getCurrentCompany()->getId());

        $mainWallet = $company->createAppWallet($this->kanvasApp, ['name' => WalletConfigurationEnum::WALLET_DEFAULT_NAME->value]);
        $holdWallet = $company->createAppWallet($this->kanvasApp, ['name' => 'checkout_hold']);

        if ($fund > 0.0) {
            $mainWallet->depositFloat($fund, ['source' => 'test_setup']);
        }

        return ['company' => $company, 'mainWallet' => $mainWallet, 'holdWallet' => $holdWallet];
    }

    public function testWalletHoldCreatedAtFinalization(): void
    {
        $user = auth()->user();
        ['company' => $company, 'mainWallet' => $mainWallet] = $this->getCompanyWallet(200.0);

        $snapshotUuid = (string) Str::uuid();

        $holdWallet = $company->createAppWallet($this->kanvasApp, ['name' => 'checkout_hold']);

        $balanceBefore = $mainWallet->balanceFloat;

        $transfer = $mainWallet->transferFloat(
            wallet: $holdWallet,
            amount: 80.0,
            meta: [
                'snapshot_uuid' => $snapshotUuid,
                'type' => 'checkout_hold',
                'order_uuid' => 'order-test-uuid',
            ]
        );

        $mainWallet->refreshBalance();
        $holdWallet->refreshBalance();

        $this->assertNotNull($transfer);
        $this->assertEqualsWithDelta($balanceBefore - 80.0, $mainWallet->balanceFloat, 0.01);
        $this->assertGreaterThanOrEqual(80.0, $holdWallet->balanceFloat);
    }

    public function testTwoCheckoutsCannotBothHoldSameBalance(): void
    {
        ['company' => $company, 'mainWallet' => $mainWallet] = $this->getCompanyWallet(100.0);

        $holdWallet = $company->createAppWallet($this->kanvasApp, ['name' => 'checkout_hold']);

        $mainWallet->transferFloat(
            wallet: $holdWallet,
            amount: 80.0,
            meta: ['snapshot_uuid' => 'snap-1', 'type' => 'checkout_hold', 'order_uuid' => 'order-1']
        );

        $mainWallet->refreshBalance();

        $this->expectException(Throwable::class);

        $mainWallet->transferFloat(
            wallet: $holdWallet,
            amount: 80.0,
            meta: ['snapshot_uuid' => 'snap-2', 'type' => 'checkout_hold', 'order_uuid' => 'order-2']
        );
    }

    public function testCancelledCheckoutReleasesHold(): void
    {
        ['company' => $company, 'mainWallet' => $mainWallet] = $this->getCompanyWallet(100.0);

        $holdWallet = $company->createAppWallet($this->kanvasApp, ['name' => 'checkout_hold']);

        $snapshotUuid = (string) Str::uuid();

        $transfer = $mainWallet->transferFloat(
            wallet: $holdWallet,
            amount: 60.0,
            meta: ['snapshot_uuid' => $snapshotUuid, 'type' => 'checkout_hold', 'order_uuid' => 'order-release']
        );

        $mainWallet->refreshBalance();
        $balanceAfterHold = $mainWallet->balanceFloat;

        $snapshot = $this->makeSnapshot([
            'checkout_payload' => [
                'wallet_hold_transfer_id' => $transfer->id,
            ],
        ]);

        $snapshot->uuid = $snapshotUuid;
        $snapshot->saveOrFail();

        new ReleaseCheckoutHoldsAction($snapshot)->execute();

        $mainWallet->refreshBalance();

        $this->assertEqualsWithDelta($balanceAfterHold + 60.0, $mainWallet->balanceFloat, 0.01);
    }

    public function testCompletionConsumesHoldOnce(): void
    {
        ['company' => $company, 'mainWallet' => $mainWallet] = $this->getCompanyWallet(100.0);

        $holdWallet = $company->createAppWallet($this->kanvasApp, ['name' => 'checkout_hold']);

        $snapshotUuid = (string) Str::uuid();

        $transfer = $mainWallet->transferFloat(
            wallet: $holdWallet,
            amount: 50.0,
            meta: ['snapshot_uuid' => $snapshotUuid, 'type' => 'checkout_hold', 'order_uuid' => 'order-idempotent']
        );

        $snapshot = $this->makeSnapshot([
            'checkout_payload' => [
                'wallet_hold_transfer_id' => $transfer->id,
            ],
        ]);

        $snapshot->uuid = $snapshotUuid;
        $snapshot->saveOrFail();

        new ReleaseCheckoutHoldsAction($snapshot)->execute();

        $mainWallet->refreshBalance();
        $balanceAfterFirstRelease = $mainWallet->balanceFloat;

        new ReleaseCheckoutHoldsAction($snapshot)->execute();

        $mainWallet->refreshBalance();

        $this->assertEqualsWithDelta($balanceAfterFirstRelease, $mainWallet->balanceFloat, 0.01);
    }
}
