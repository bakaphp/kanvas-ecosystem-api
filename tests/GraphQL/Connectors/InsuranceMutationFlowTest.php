<?php

declare(strict_types=1);

namespace Tests\GraphQL\Connectors;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Insurance\Contracts\InsuranceProcessorInterface;
use Kanvas\Souk\Orders\Models\Order;
use Tests\TestCase;

/**
 * Covers the provider-agnostic insurance* mutations. New insurance providers should only
 * need a new `insurance_processor.{provider}` binding — never a new set of
 * `{provider}CreateQuote` / `{provider}RequestPaymentLink` / `{provider}EmitPolicy` mutations.
 */
class InsuranceMutationFlowTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['ecosystem', 'commerce'];

    private Apps $currentApp;
    private Companies $currentCompany;
    private int $regionId;
    private int $peopleId;
    private FakeInsuranceProcessor $fakeProcessor;

    public function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->currentCompany = static::$cachedUser->getCurrentCompany();
        $this->regionId = Regions::getDefault($this->currentCompany, $this->currentApp)->id;
        $this->peopleId = People::first()->id;

        $this->fakeProcessor = new FakeInsuranceProcessor();

        $this->app->bind('insurance_processor.fake_provider', fn () => $this->fakeProcessor);
    }

    public function testCreateQuoteRoutesToTheRequestedProvider(): void
    {
        $order = $this->buildOrder();
        $this->fakeProcessor->quoteResponse = ['numeroCotizacion' => 'Q-123'];

        $response = $this->graphQL('
            mutation($provider: String!, $orderId: ID!, $product: String!, $input: Mixed!) {
                insuranceCreateQuote(provider: $provider, order_id: $orderId, product: $product, input: $input)
            }
        ', [
            'provider' => 'fake_provider',
            'orderId' => (string) $order->id,
            'product' => 'A-PA',
            'input' => ['foo' => 'bar'],
        ], [], [
            'X-Kanvas-Location' => $this->currentCompany->branch->uuid,
        ]);

        $response->assertSuccessful();
        $this->assertSame(['numeroCotizacion' => 'Q-123'], $response->json('data.insuranceCreateQuote'));

        $this->assertSame($order->id, $this->fakeProcessor->quotedOrder->id);
        $this->assertSame('A-PA', $this->fakeProcessor->quotedProduct);
        $this->assertSame(['foo' => 'bar'], $this->fakeProcessor->quotedInput);
    }

    public function testRequestPaymentLinkRoutesToTheRequestedProvider(): void
    {
        $order = $this->buildOrder();
        $this->fakeProcessor->paymentLinkResponse = ['url' => 'https://pay.example/abc'];

        $response = $this->graphQL('
            mutation($provider: String!, $orderId: ID!) {
                insuranceRequestPaymentLink(provider: $provider, order_id: $orderId, by_email: true)
            }
        ', [
            'provider' => 'fake_provider',
            'orderId' => (string) $order->id,
        ], [], [
            'X-Kanvas-Location' => $this->currentCompany->branch->uuid,
        ]);

        $response->assertSuccessful();
        $this->assertSame(['url' => 'https://pay.example/abc'], $response->json('data.insuranceRequestPaymentLink'));
        $this->assertTrue($this->fakeProcessor->paymentLinkByEmail);
        $this->assertSame($order->id, $this->fakeProcessor->paymentLinkOrder->id);
    }

    public function testEmitPolicyRoutesToTheRequestedProvider(): void
    {
        $order = $this->buildOrder();
        $this->fakeProcessor->emitPolicyResponse = ['numeroPoliza' => 'P-999'];

        $response = $this->graphQL('
            mutation($provider: String!, $orderId: ID!) {
                insuranceEmitPolicy(provider: $provider, order_id: $orderId)
            }
        ', [
            'provider' => 'fake_provider',
            'orderId' => (string) $order->id,
        ], [], [
            'X-Kanvas-Location' => $this->currentCompany->branch->uuid,
        ]);

        $response->assertSuccessful();
        $this->assertSame(['numeroPoliza' => 'P-999'], $response->json('data.insuranceEmitPolicy'));
        $this->assertSame($order->id, $this->fakeProcessor->emittedOrder->id);
    }

    public function testUnknownProviderReturnsAnError(): void
    {
        $order = $this->buildOrder();

        $response = $this->graphQL('
            mutation($provider: String!, $orderId: ID!) {
                insuranceEmitPolicy(provider: $provider, order_id: $orderId)
            }
        ', [
            'provider' => 'does_not_exist',
            'orderId' => (string) $order->id,
        ], [], [
            'X-Kanvas-Location' => $this->currentCompany->branch->uuid,
        ]);

        $this->assertNotEmpty($response->json('errors'));
    }

    private function buildOrder(): Order
    {
        $order = new Order();
        $order->apps_id = $this->currentApp->getId();
        $order->companies_id = $this->currentCompany->getId();
        $order->users_id = static::$cachedUser->getId();
        $order->region_id = $this->regionId;
        $order->people_id = $this->peopleId;
        $order->order_number = random_int(10000, 999999);
        $order->total_gross_amount = 100.00;
        $order->total_net_amount = 100.00;
        $order->status = 'draft';
        $order->fulfillment_status = 'pending';
        $order->currency = 'USD';
        $order->is_deleted = false;
        $order->saveOrFail();

        return $order;
    }
}

class FakeInsuranceProcessor implements InsuranceProcessorInterface
{
    public array $quoteResponse = [];
    public array $paymentLinkResponse = [];
    public array $emitPolicyResponse = [];

    public ?Order $quotedOrder = null;
    public ?string $quotedProduct = null;
    public array $quotedInput = [];

    public ?Order $paymentLinkOrder = null;
    public bool $paymentLinkByEmail = false;

    public ?Order $emittedOrder = null;

    public function name(): string
    {
        return 'fake_provider';
    }

    public function createQuote(Order $order, string $product, array $input): array
    {
        $this->quotedOrder = $order;
        $this->quotedProduct = $product;
        $this->quotedInput = $input;

        return $this->quoteResponse;
    }

    public function requestPaymentLink(Order $order, bool $byEmail = false): array
    {
        $this->paymentLinkOrder = $order;
        $this->paymentLinkByEmail = $byEmail;

        return $this->paymentLinkResponse;
    }

    public function emitPolicy(Order $order): array
    {
        $this->emittedOrder = $order;

        return $this->emitPolicyResponse;
    }
}
