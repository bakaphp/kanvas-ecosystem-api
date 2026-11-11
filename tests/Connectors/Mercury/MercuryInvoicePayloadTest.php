<?php

declare(strict_types=1);

namespace Tests\Connectors\Mercury;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Kanvas\Connectors\Mercury\Actions\PushInvoiceToMercuryAction;
use Kanvas\Connectors\Mercury\Client;
use Kanvas\Connectors\Mercury\DataTransferObject\MercuryInvoice;
use Kanvas\Connectors\Mercury\Enums\CustomFieldEnum;
use Kanvas\Connectors\Mercury\Services\MercuryCustomerService;
use Kanvas\Connectors\Mercury\Services\MercuryInvoiceService;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Banking\Actions\CreateBankAccountAction;
use Kanvas\Scribe\Banking\DataTransferObject\BankAccount as BankAccountData;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Tests\Connectors\Traits\HasMercuryConfiguration;
use Tests\Scribe\ScribeTestCase;

/**
 * Kanvas sends every AR document itself; Mercury is only the payment rail. These pin the create body so a
 * Mercury invoice never emails the customer and never asks for a payment method Mercury will 400 on.
 */
final class MercuryInvoicePayloadTest extends ScribeTestCase
{
    use HasMercuryConfiguration;

    private const string MERCURY_ACCOUNT_ID = 'acct-99999999-2222-3333-4444-555555555555';

    protected function afterScribeSetUp(): void
    {
        $this->configureMercury($this->company);

        new CreateBankAccountAction(
            data: new BankAccountData(
                app: $this->kanvasApp,
                company: $this->company,
                account_name: 'Test Checking',
                gl_account_id: $this->accountIdBySubType(AccountSubTypeEnum::CASH_CHECKING),
                currency: 'USD',
                source: 'mercury',
                external_id: self::MERCURY_ACCOUNT_ID,
            ),
            user: static::$cachedUser,
        )->execute();
    }

    public function testAUsdInvoiceIsNeverEmailedByMercuryAndOffersAchButNotCardByDefault(): void
    {
        $payload = MercuryInvoice::payloadFromInvoice(
            invoice: $this->issueTestInvoice($this->customer(), 1_000.00),
            mercuryCustomerId: 'mcus-1',
            destinationAccountId: self::MERCURY_ACCOUNT_ID,
        );

        $this->assertSame('DontSend', $payload['sendEmailOption']);
        $this->assertSame('USD', $payload['currencyCode']);
        $this->assertTrue($payload['achDebitEnabled']);
        $this->assertFalse($payload['creditCardEnabled'], 'Card 400s unless Stripe is connected in Mercury.');
    }

    public function testCardIsOfferedOnlyWhenTheCompanyEnabledIt(): void
    {
        $payload = MercuryInvoice::payloadFromInvoice(
            invoice: $this->issueTestInvoice($this->customer(), 1_000.00),
            mercuryCustomerId: 'mcus-1',
            destinationAccountId: self::MERCURY_ACCOUNT_ID,
            cardEnabled: true,
        );

        $this->assertTrue($payload['creditCardEnabled']);
        $this->assertSame('DontSend', $payload['sendEmailOption']);
    }

    public function testANonUsdInvoiceTurnsAchDebitOffAndSendsItsCurrency(): void
    {
        $invoice = $this->issueTestInvoice($this->customer(), 1_000.00);
        $invoice->currency = 'eur';

        $payload = MercuryInvoice::payloadFromInvoice(
            invoice: $invoice,
            mercuryCustomerId: 'mcus-1',
            destinationAccountId: self::MERCURY_ACCOUNT_ID,
        );

        $this->assertSame('EUR', $payload['currencyCode']);
        $this->assertFalse($payload['achDebitEnabled'], 'Mercury 400s ACH debit on a non-USD invoice.');
    }

    public function testThePushSendsADontSendBodyAndStoresThePayLinkOnTheInvoice(): void
    {
        $customer = $this->customer();
        $invoice = $this->issueTestInvoice($customer, 2_000.00);

        $history = [];
        $invoiceService = new MercuryInvoiceService(
            $this->kanvasApp,
            $this->company,
            $this->recordingClient($history, [
                'id' => 'minv-payload-1',
                'invoiceNumber' => $invoice->invoice_number,
                'status' => 'Unpaid',
                'amount' => 2_000.00,
                'slug' => 'pay-slug-1',
            ]),
        );

        new PushInvoiceToMercuryAction(
            invoice: $invoice,
            invoiceService: $invoiceService,
            customerService: new MercuryCustomerService(
                $this->kanvasApp,
                $this->company,
                $this->mercuryClientReturning($this->kanvasApp, $this->company, [
                    ['id' => 'mcus-payload-1', 'name' => 'Payload Co', 'email' => 'ap@payload.test'],
                ]),
            ),
        )->execute();

        $this->assertCount(1, $history);
        $body = json_decode((string) $history[0]['request']->getBody(), true);

        $this->assertSame('ar/invoices', $history[0]['request']->getUri()->getPath());
        $this->assertSame('DontSend', $body['sendEmailOption']);
        $this->assertFalse($body['creditCardEnabled']);
        $this->assertTrue($body['achDebitEnabled']);

        $invoice->refresh();
        $this->assertSame('https://mercury.com/pay/pay-slug-1', $invoice->external_url);
        $this->assertSame('minv-payload-1', (string) $invoice->get(CustomFieldEnum::INVOICE_ID->value));
    }

    private function customer(): Organization
    {
        $organization = $this->seedTestOrganization('Payload Co');
        $organization->email = 'ap@payload.test';
        $organization->saveQuietly();

        return $organization;
    }

    /**
     * @param array<int, array<string, mixed>> $history
     * @param array<string, mixed> $response
     */
    private function recordingClient(array &$history, array $response): Client
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($response)),
        ]));
        $stack->push(Middleware::history($history));

        return new Client(
            $this->kanvasApp,
            $this->company,
            new GuzzleClient(['handler' => $stack]),
        );
    }
}
