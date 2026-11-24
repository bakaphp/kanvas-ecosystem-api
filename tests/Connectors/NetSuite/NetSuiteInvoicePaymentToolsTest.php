<?php

declare(strict_types=1);

namespace Tests\Connectors\NetSuite;

use Illuminate\Support\Str;
use Kanvas\Connectors\NetSuite\Actions\PullNetSuiteInvoiceAction;
use Kanvas\Connectors\NetSuite\Neuron\Tools\ImportNetSuiteInvoiceTool;
use Kanvas\Connectors\Stripe\Neuron\Tools\CreateInvoicePaymentLinkTool;
use Kanvas\Connectors\Stripe\Services\StripePaymentLinkService;
use Override;
use Stripe\PaymentLink;
use Tests\Connectors\NetSuite\Concerns\BuildsNetSuiteRecords;
use Tests\Connectors\Stripe\Fakes\FakeStripeClient;
use Tests\Scribe\ScribeTestCase;

/**
 * What a user does through the agent: "import NetSuite invoice 2611599", then "give me a payment link".
 */
class NetSuiteInvoicePaymentToolsTest extends ScribeTestCase
{
    use BuildsNetSuiteRecords;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'accounting', 'intelligence', 'crm', 'social', 'commerce'];

    private string $run;
    private FakeStripeClient $stripe;

    protected function afterScribeSetUp(): void
    {
        $this->run = (string) random_int(10000000, 99999999);
        $this->stripe = new FakeStripeClient();
        $this->fakeNetSuite($this->company);
        $this->netsuiteCustomer('C' . $this->run, 'julie+' . Str::random(8) . '@artsupplydepo.test');

        $lines = [['45' . $this->run . '01', 'Copic Sketch Marker Black', 6, 3.14]];
        $this->netsuiteItem($lines[0][0], $lines[0][1]);
        $this->netsuiteSalesOrder('SO' . $this->run, 'C' . $this->run, $lines);
        $this->netsuiteInvoice(
            'INV' . $this->run,
            '26' . $this->run,
            'C' . $this->run,
            'SO' . $this->run,
            $lines
        );
    }

    private function importTool(): ImportNetSuiteInvoiceTool
    {
        $test = $this;

        return new class ($test) extends ImportNetSuiteInvoiceTool {
            public function __construct(private readonly NetSuiteInvoicePaymentToolsTest $test)
            {
            }

            #[Override]
            protected function importAction(): PullNetSuiteInvoiceAction
            {
                return $this->test->wiredInvoiceAction(parent::importAction());
            }
        }->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }

    public function wiredInvoiceAction(PullNetSuiteInvoiceAction $action): PullNetSuiteInvoiceAction
    {
        return $this->wireInvoiceAction($action);
    }

    private function linkTool(): CreateInvoicePaymentLinkTool
    {
        $stripe = $this->stripe;

        return new class ($stripe) extends CreateInvoicePaymentLinkTool {
            public function __construct(private readonly FakeStripeClient $stripe)
            {
            }

            #[Override]
            protected function paymentLinkService(): StripePaymentLinkService
            {
                return new StripePaymentLinkService($this->app, $this->company, $this->stripe);
            }
        }->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }

    public function test_the_agent_imports_the_invoice_then_hands_back_a_link_for_its_balance(): void
    {
        $imported = ($this->importTool())(invoice_number: '26' . $this->run);

        $this->assertTrue($imported['success'], json_encode($imported));
        $this->assertEqualsWithDelta(18.84, $imported['invoice']['balance_due'], 0.001);
        $this->assertNotNull($imported['order']);
        $this->assertSame(1, $imported['order']['lines']);

        $this->stripe->getPaymentLinks()->queueResponse('create', PaymentLink::constructFrom([
            'id' => 'plink_' . $this->run,
            'url' => 'https://buy.stripe.com/' . $this->run,
            'active' => true,
        ]));
        $link = ($this->linkTool())(invoice_id: $imported['invoice']['id']);

        $this->assertTrue($link['success'], json_encode($link));
        $this->assertSame('https://buy.stripe.com/' . $this->run, $link['payment_link']);
        $this->assertEqualsWithDelta(18.84, $link['amount'], 0.001);
        $this->assertStringContainsString('Nothing was sent', $link['note']);
    }

    public function test_an_unknown_invoice_number_reads_as_not_found_and_imports_nothing(): void
    {
        $result = ($this->importTool())(invoice_number: 'nope');

        $this->assertSame('not_found', $result['outcome']);
        $this->assertArrayNotHasKey('invoice', $result);
    }

    public function test_the_import_tool_needs_a_number_or_an_id(): void
    {
        $result = ($this->importTool())();

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_args', $result['outcome']);
    }

    public function test_a_link_for_an_invoice_of_another_company_is_not_found(): void
    {
        $result = ($this->linkTool())(invoice_id: 999999999);

        $this->assertSame('not_found', $result['outcome']);
        $this->assertArrayNotHasKey('payment_link', $result);
    }
}
