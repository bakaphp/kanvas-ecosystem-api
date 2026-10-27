<?php

declare(strict_types=1);

namespace Tests\Connectors\Stripe;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Providers\StripeTaxProvider;
use Kanvas\Connectors\Stripe\Services\StripeTaxService;
use Kanvas\Souk\Taxes\DataTransferObject\TaxAddress;
use Kanvas\Souk\Taxes\DataTransferObject\TaxContext;
use Kanvas\Souk\Taxes\DataTransferObject\TaxReversal;
use Kanvas\Souk\Taxes\DataTransferObject\TaxableLine;
use Kanvas\Souk\Taxes\Enums\TaxTreatmentEnum;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use Mockery;
use RuntimeException;
use Stripe\Account;
use Stripe\Collection;
use Stripe\StripeObject;
use Tests\Connectors\Stripe\Fakes\FakeStripeClient;
use Tests\TestCase;

final class StripeTaxProviderTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['commerce'];

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeProvider(FakeStripeClient $client, ?StripeTaxService $taxService = null): StripeTaxProvider
    {
        $taxService ??= $this->mockTaxService($client);

        return new StripeTaxProvider(
            app: $this->kanvasApp,
            company: $this->company,
            taxService: $taxService,
            clientOverride: $client,
        );
    }

    private function mockTaxService(FakeStripeClient $client): StripeTaxService
    {
        $service = Mockery::mock(StripeTaxService::class);
        $service->shouldReceive('client')->andReturn($client)->byDefault();
        $service->shouldReceive('accountFingerprint')->andReturn('fake-fingerprint-abc123')->byDefault();

        return $service;
    }

    private function makeContext(
        string $shipToCountry = 'US',
        string $shipFromCountry = 'US',
        array $lineOverrides = [],
        string $taxDate = '',
    ): TaxContext {
        $line = new TaxableLine(
            lineId: $lineOverrides['lineId'] ?? 'line-1',
            reference: $lineOverrides['reference'] ?? 'item-1',
            unitPrice: $lineOverrides['unitPrice'] ?? '100.00',
            quantity: $lineOverrides['quantity'] ?? 1,
            isTaxable: $lineOverrides['isTaxable'] ?? true,
            taxCategory: $lineOverrides['taxCategory'] ?? 'general_tangible',
            taxCodeOverride: $lineOverrides['taxCodeOverride'] ?? null,
            isShipping: $lineOverrides['isShipping'] ?? false,
        );

        return new TaxContext(
            appId: (string) $this->kanvasApp->getId(),
            shipTo: new TaxAddress(
                country: $shipToCountry,
                state: 'CA',
                city: 'San Francisco',
                postalCode: '94105',
            ),
            shipFrom: new TaxAddress(
                country: $shipFromCountry,
                state: 'NY',
                city: 'New York',
                postalCode: '10001',
            ),
            lines: [$line],
            currency: 'USD',
            taxDate: $taxDate,
        );
    }

    private function fakeCalculation(
        string $id = 'taxcalc_test_001',
        int $taxAmountExclusive = 975,
        int $taxableAmount = 10000,
    ): StripeObject {
        $lineItem = StripeObject::constructFrom([
            'id' => 'tax_li_001',
            'object' => 'tax.calculation_line_item',
            'amount' => $taxableAmount,
            'amount_tax' => $taxAmountExclusive,
            'reference' => 'line-1',
            'tax_breakdown' => [
                [
                    'amount' => $taxAmountExclusive,
                    'jurisdiction' => [
                        'country' => 'US',
                        'display_name' => 'California',
                        'level' => 'state',
                        'state' => 'CA',
                    ],
                    'sourcing' => 'destination',
                    'tax_rate_details' => [
                        'display_name' => 'CA Sales Tax',
                        'percentage_decimal' => '9.75',
                        'type' => 'sales_tax',
                    ],
                    'taxability_reason' => 'standard_rated',
                    'taxable_amount' => $taxableAmount,
                ],
            ],
        ]);

        $lineItems = StripeObject::constructFrom([
            'object' => 'list',
            'data' => [$lineItem],
            'has_more' => false,
            'url' => '/v1/tax/calculations/' . $id . '/line_items',
        ]);

        return StripeObject::constructFrom([
            'id' => $id,
            'object' => 'tax.calculation',
            'currency' => 'usd',
            'tax_amount_exclusive' => $taxAmountExclusive,
            'taxable_amount' => $taxableAmount,
            'line_items' => $lineItems,
        ]);
    }

    private function fakeTransaction(string $id = 'tax_txn_001'): StripeObject
    {
        return StripeObject::constructFrom([
            'id' => $id,
            'object' => 'tax.transaction',
            'type' => 'transaction',
        ]);
    }

    private function fakeAccount(string $id = 'acct_test_001'): Account
    {
        return Account::constructFrom([
            'id' => $id,
            'object' => 'account',
        ]);
    }

    public function testCalculateReturnsCorrectTaxForUsAddress(): void
    {
        $client = new FakeStripeClient();
        $client->getTax()->calculations->queueResponse('create', $this->fakeCalculation());

        $provider = $this->makeProvider($client);
        $result = $provider->calculate($this->makeContext());

        $this->assertSame(TaxTreatmentEnum::TAXED, $result->treatment);
        $this->assertSame('9.75', $result->totalTax);
        $this->assertSame('100.00', $result->totalTaxable);
        $this->assertSame('taxcalc_test_001', $result->providerTransactionId);
        $this->assertSame('fake-fingerprint-abc123', $result->metadata['account_fingerprint']);

        $createCalls = $client->getTax()->calculations->getCalls('create');
        $this->assertCount(1, $createCalls);
        $this->assertSame('usd', $createCalls[0]['params']['currency']);
        $this->assertSame('US', $createCalls[0]['params']['ship_from_details']['address']['country']);
        $this->assertSame('US', $createCalls[0]['params']['customer_details']['address']['country']);
    }

    public function testCalculateReturnsFailedForTaxDateOutsideRange(): void
    {
        $client = new FakeStripeClient();
        $provider = $this->makeProvider($client);

        $futureDate = now()->addDays(60)->toDateString();
        $result = $provider->calculate($this->makeContext(taxDate: $futureDate));

        $this->assertSame(TaxTreatmentEnum::NO_BASE, $result->treatment);
        $this->assertSame('tax_date_unsupported', $result->failureReason);
        $this->assertCount(0, $client->getTax()->calculations->getCalls('create'));
    }

    public function testCalculateReturnsFailedForMissingShipFrom(): void
    {
        $client = new FakeStripeClient();
        $provider = $this->makeProvider($client);

        $ctx = new TaxContext(
            appId: (string) $this->kanvasApp->getId(),
            shipTo: new TaxAddress(country: 'US', state: 'CA', city: 'San Francisco', postalCode: '94105'),
            shipFrom: null,
            lines: [],
            currency: 'USD',
        );

        $result = $provider->calculate($ctx);

        $this->assertSame(TaxTreatmentEnum::NO_BASE, $result->treatment);
        $this->assertSame('shipping_address_invalid', $result->failureReason);
        $this->assertCount(0, $client->getTax()->calculations->getCalls('create'));
    }

    public function testCalculateReturnsFailedForInvalidTaxCodeOverride(): void
    {
        $client = new FakeStripeClient();
        $provider = $this->makeProvider($client);

        $ctx = $this->makeContext(lineOverrides: ['taxCodeOverride' => 'not_a_valid_ptc_code']);
        $result = $provider->calculate($ctx);

        $this->assertSame(TaxTreatmentEnum::NO_BASE, $result->treatment);
        $this->assertSame('invalid_tax_code_override', $result->failureReason);
        $this->assertCount(0, $client->getTax()->calculations->getCalls('create'));
    }

    public function testNotCollectingBlocksCheckout(): void
    {
        $notCollectingCalc = StripeObject::constructFrom([
            'id' => 'taxcalc_nc_001',
            'object' => 'tax.calculation',
            'currency' => 'usd',
            'tax_amount_exclusive' => 0,
            'taxable_amount' => 10000,
            'not_collecting' => true,
            'line_items' => StripeObject::constructFrom([
                'object' => 'list',
                'data' => [],
                'has_more' => false,
                'url' => '/v1/tax/calculations/taxcalc_nc_001/line_items',
            ]),
        ]);

        $client = new FakeStripeClient();
        $client->getTax()->calculations->queueResponse('create', $notCollectingCalc);

        $provider = $this->makeProvider($client);
        $result = $provider->calculate($this->makeContext());

        $this->assertSame(TaxTreatmentEnum::NOT_COLLECTING, $result->treatment);
        $this->assertSame('not_collecting', $result->failureReason);
    }

    public function testCommitIsIdempotent(): void
    {
        $client = new FakeStripeClient();
        $client->getTax()->transactions->queueResponse('createFromCalculation', $this->fakeTransaction());

        $provider = $this->makeProvider($client);

        $snapshot = $this->buildSnapshot('snap-uuid-123', [
            'calculation_id' => 'taxcalc_001',
            'account_fingerprint' => 'fake-fingerprint-abc123',
        ]);

        $provider->commit($snapshot);
        $provider->commit($snapshot);

        $calls = $client->getTax()->transactions->getCalls('createFromCalculation');
        $this->assertCount(1, $calls);
        $this->assertSame('taxcalc_001', $calls[0]['params']['calculation']);
        $this->assertSame('snap-uuid-123', $calls[0]['params']['reference']);
    }

    public function testAccountMismatchOnCommit(): void
    {
        $client = new FakeStripeClient();
        $provider = $this->makeProvider($client);

        $snapshot = $this->buildSnapshot('snap-mismatch-456', [
            'calculation_id' => 'taxcalc_002',
            'account_fingerprint' => 'different-fingerprint-xyz',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('account_mismatch');

        $provider->commit($snapshot);
    }

    private function buildSnapshot(string $uuid, array $metadata): OrderTaxSnapshot
    {
        $snapshot = Mockery::mock(OrderTaxSnapshot::class)->makePartial();
        $snapshot->uuid = $uuid;
        $snapshot->metadata = $metadata;
        $snapshot->shouldReceive('saveQuietly')->andReturnUsing(function () use ($snapshot) {
            return true;
        })->byDefault();

        return $snapshot;
    }
}
