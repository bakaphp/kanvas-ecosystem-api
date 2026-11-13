<?php

declare(strict_types=1);

namespace Tests\Connectors\CardNet\Ztrans;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\CardNet\Ztrans\Exceptions\ZtransException;
use Kanvas\Connectors\CardNet\Ztrans\Exceptions\ZtransUnknownOutcomeException;
use Kanvas\Connectors\CardNet\Ztrans\ThreeDSServerClient;
use Kanvas\Connectors\CardNet\Ztrans\ZtransClient;
use Tests\Connectors\CardNet\Ztrans\Concerns\BuildsZtransFixtures;
use Tests\TestCase;

class ZtransClientTest extends TestCase
{
    use BuildsZtransFixtures;

    private Apps $currentApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = $this->fakeApp();
    }

    public function testIdempotencyKeyStripsIkeyPrefix(): void
    {
        Http::fake(['*/idenpotency-keys' => Http::response('ikey:11b0de52dec7423db5815be48f914239')]);

        $this->assertSame('11b0de52dec7423db5815be48f914239', new ZtransClient($this->currentApp)->createIdempotencyKey());
    }

    public function testSaleInjectsMerchantAndTerminal(): void
    {
        Http::fake(['*/transactions/sales' => Http::response($this->approved())]);

        $response = new ZtransClient($this->currentApp)->sale(['amount' => 10]);

        $this->assertTrue($response->isApproved());
        $this->assertSame('txn-100', $response->reference);
        Http::assertSent(fn (Request $request): bool => $request['merchant-id'] === '100000001'
            && $request['terminal-id'] === '00000001'
            && str_starts_with($request->url(), 'https://labservicios.cardnet.com.do/api/payment/'));
    }

    public function testConnectionFailureIsAnUnknownOutcome(): void
    {
        Http::fake(['*/transactions/sales' => Http::sequence()->pushFailedConnection()]);

        $this->expectException(ZtransUnknownOutcomeException::class);

        new ZtransClient($this->currentApp)->sale(['amount' => 10]);
    }

    public function testUnreadableGatewayErrorIsAnUnknownOutcome(): void
    {
        Http::fake(['*/transactions/sales' => Http::response('<html>gateway error</html>', 502)]);

        $this->expectException(ZtransUnknownOutcomeException::class);

        new ZtransClient($this->currentApp)->sale(['amount' => 10]);
    }

    public function testUnreadableClientErrorIsARejection(): void
    {
        Http::fake(['*/transactions/sales' => Http::response('<html>bad request</html>', 400)]);

        try {
            new ZtransClient($this->currentApp)->sale(['amount' => 10]);
            $this->fail('Expected a rejection.');
        } catch (ZtransException $e) {
            $this->assertNotInstanceOf(ZtransUnknownOutcomeException::class, $e);
        }
    }

    public function testRefundSendsBearerTokenAndOperation(): void
    {
        Http::fake(['*/transactions/refund' => Http::response($this->approved())]);

        new ZtransClient($this->currentApp)->refund(['amount' => 10]);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer bearer-token')
            && $request['Operation'] === 'refund');
    }

    public function testAuthenticationSignsTheRequest(): void
    {
        Http::fake(['*/server/authentication' => Http::response($this->authenticationFrictionless())]);

        new ThreeDSServerClient($this->currentApp)->authenticate([
            'integratorTxId' => 'tx-1',
            'cardExpiryDate' => '2712',
            'purchaseAmount' => '10000',
        ]);

        Http::assertSent(fn (Request $request): bool => $request['integratorCode'] === '100000001'
            && $request['signature'] === hash('sha512', '1000000012712tx-110000test-api-key'));
    }

    public function testStatusSignsWithoutExpiry(): void
    {
        Http::fake(['*/server/status' => Http::response($this->statusAuthenticated())]);

        new ThreeDSServerClient($this->currentApp)->status('tds-1', 'tx-1', '10000');

        Http::assertSent(fn (Request $request): bool => $request['threeDSServerTransID'] === 'tds-1'
            && $request['signature'] === hash('sha512', '100000001tx-110000test-api-key'));
    }
}
