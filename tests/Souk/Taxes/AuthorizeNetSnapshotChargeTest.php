<?php

declare(strict_types=1);

namespace Tests\Souk\Taxes;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Souk\Orders\DataTransferObject\DirectOrder;
use Kanvas\Souk\Payments\DataTransferObject\CreditCard;
use Kanvas\Souk\Payments\Providers\AuthorizeNetPaymentProcessor;
use Kanvas\Souk\Taxes\Enums\ConfigurationEnum;
use Kanvas\Souk\Taxes\Enums\GatewayAttemptStatusEnum;
use Kanvas\Souk\Taxes\Enums\TaxStatusEnum;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use Kanvas\Users\Models\Users;
use net\authorize\api\contract\v1\ANetApiResponseType;
use net\authorize\api\contract\v1 as AnetAPI;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Wearepixel\Cart\Cart;

#[Group('serial')]
final class AuthorizeNetSnapshotChargeTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce'];

    private Apps $kanvasApp;

    private CompaniesBranches $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);

        $branch = CompaniesBranches::where('companies_id', auth()->user()->getCurrentCompany()->getId())->first();

        if ($branch === null) {
            $this->markTestSkipped('No companies branch available for this company');
        }

        $this->branch = $branch;
    }

    protected function tearDown(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::TAX_ENGINE_ENABLED->value, 0);

        parent::tearDown();
    }

    public function testChargesTheFrozenGatewayDueAndRecordsTheTransaction(): void
    {
        $cart = $this->makeCart();
        $snapshot = $this->createSnapshot($cart->getSessionKey());
        $processor = $this->processorAnswering('Ok', 'txn-777');

        $processor->processCreditCardPayment($this->directOrder($cart));

        $transaction = $processor->sent->getTransactionRequest();
        $this->assertSame(118.0, (float) $transaction->getAmount());
        $this->assertSame('MREF-0001', $transaction->getOrder()->getInvoiceNumber());
        $this->assertSame(substr($snapshot->gateway_attempt_key, 0, 20), $processor->sent->getRefId());
        $this->assertSame('txn-777', $snapshot->refresh()->payment_intent_id);
    }

    public function testADeclinedChargeLeavesTheSnapshotWithoutATransaction(): void
    {
        $cart = $this->makeCart();
        $snapshot = $this->createSnapshot($cart->getSessionKey());

        $this->processorAnswering('Error', 'txn-declined')->processCreditCardPayment($this->directOrder($cart));

        $this->assertNull($snapshot->refresh()->payment_intent_id);
    }

    public function testASnapshotAlreadyBoundToAnOrderIsNotReusedForANewCharge(): void
    {
        $cart = $this->makeCart();
        $snapshot = $this->createSnapshot($cart->getSessionKey());
        $snapshot->order_id = 1;
        $snapshot->saveOrFail();
        $this->kanvasApp->set(ConfigurationEnum::TAX_ENGINE_ENABLED->value, 1);
        $processor = $this->processorAnswering('Ok', 'txn-never');

        try {
            $processor->processCreditCardPayment($this->directOrder($cart));
            $this->fail('Expected tax_snapshot_required');
        } catch (ValidationException $e) {
            $this->assertSame(['tax' => ['tax_snapshot_required']], $e->errors());
        }

        $this->assertNull($processor->sent);
    }

    private function makeCart(): Cart
    {
        $cart = app('cart')->session('authnet-snapshot-' . uniqid());
        $cart->clear();
        $cart->add([
            'id' => 99999,
            'name' => 'Test Product',
            'price' => 100.00,
            'quantity' => 1,
            'attributes' => [],
        ]);

        return $cart;
    }

    private function createSnapshot(string $cartSessionId): OrderTaxSnapshot
    {
        $snapshot = new OrderTaxSnapshot();
        $snapshot->apps_id = $this->kanvasApp->getId();
        $snapshot->order_id = null;
        $snapshot->cart_session_id = $cartSessionId;
        $snapshot->kind = 'calculation';
        $snapshot->treatment = 'taxed';
        $snapshot->status = TaxStatusEnum::CALCULATED->value;
        $snapshot->provider = 'manual_rate';
        $snapshot->input_hash = hash('sha256', uniqid());
        $snapshot->tax_amount = 18.00;
        $snapshot->taxable_amount = 100.00;
        $snapshot->remaining_reversible_amount = 100.00;
        $snapshot->remaining_reversible_tax = 18.00;
        $snapshot->merchant_reference = 'MREF-0001';
        $snapshot->gateway_attempt_key = hash('sha256', uniqid());
        $snapshot->gateway_attempt_status = GatewayAttemptStatusEnum::PENDING->value;
        $snapshot->souk_tax_engine_enabled = true;
        $snapshot->checkout_payload = ['totals' => ['subtotal' => 100.00, 'tax' => 18.00, 'gateway_due' => 118.00]];
        $snapshot->saveOrFail();

        return $snapshot;
    }

    private function directOrder(Cart $cart): DirectOrder
    {
        return new DirectOrder(
            app: $this->kanvasApp,
            user: Users::find(auth()->user()->getId()),
            creditCard: new CreditCard(
                name: 'Test User',
                number: '4111111111111111',
                exp_month: 12,
                exp_year: 2030,
                cvv: 123,
            ),
            cart: $cart,
        );
    }

    private function processorAnswering(string $resultCode, string $transId): AuthorizeNetPaymentProcessor
    {
        return new class ($this->kanvasApp, $this->branch, $resultCode, $transId) extends AuthorizeNetPaymentProcessor {
            public ?AnetAPI\CreateTransactionRequest $sent = null;

            public function __construct(
                Apps $app,
                CompaniesBranches $branch,
                private readonly string $resultCode,
                private readonly string $transId,
            ) {
                parent::__construct($app, $branch);
            }

            protected function sendTransaction(AnetAPI\CreateTransactionRequest $request): ANetApiResponseType
            {
                $this->sent = $request;

                $messages = new AnetAPI\MessagesType();
                $messages->setResultCode($this->resultCode);

                $transaction = new AnetAPI\TransactionResponseType();
                $transaction->setTransId($this->transId);
                $transaction->setMessages([new AnetAPI\TransactionResponseType\MessagesAType\MessageAType()]);

                $response = new AnetAPI\CreateTransactionResponse();
                $response->setMessages($messages);
                $response->setTransactionResponse($transaction);

                return $response;
            }
        };
    }
}
