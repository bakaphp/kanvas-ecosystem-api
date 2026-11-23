<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\BrushCrazy;

use ArrayObject;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\BrushCrazy\Actions\RefundOrderFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Actions\SyncSaleFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Enums\ConfigurationEnum;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Support\RefundLedger;
use Kanvas\Connectors\BrushCrazy\Workflows\Activities\PushRefundToBrushCrazyActivity;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Contracts\PaymentProcessorInterface;
use Kanvas\Souk\Payments\DataTransferObject\AuthorizeResult;
use Kanvas\Souk\Payments\DataTransferObject\CaptureResult;
use Kanvas\Souk\Payments\DataTransferObject\RefundResult;
use Kanvas\Souk\Payments\DataTransferObject\VerifyResult;
use Kanvas\Souk\Payments\DataTransferObject\VoidResult;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Enums\RefundStatusEnum;
use Kanvas\Souk\Payments\Models\PaymentRefund;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Money a site order lost to a refund moves exactly once, whichever side the refund started on.
 */
#[Group('serial')]
final class BrushCrazyRefundsTest extends TestCase
{
    use CreatesQuietOrders;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'commerce', 'workflow', 'ecosystem'];

    private const string PROCESSOR = 'bc-refund-test';
    private const string API_URL = 'https://93.184.216.34';
    private const string SECRET = 'test-secret';

    private Companies $company;

    /** Amounts the fake processor was asked to refund. */
    private ArrayObject $processorCalls;

    protected function setUp(): void
    {
        parent::setUp();

        config(['scout.driver' => 'null', 'scout.queue' => false]);
        $this->company = auth()->user()->getCurrentCompany();
        $this->processorCalls = new ArrayObject();
        $this->app->bind('payment_processor.' . self::PROCESSOR, fn () => $this->fakeProcessor());
    }

    protected function tearDown(): void
    {
        $app = app(Apps::class);
        $app->del(ConfigurationEnum::BRUSHCRAZY_API_URL->value);
        $app->del(ConfigurationEnum::BRUSHCRAZY_API_SECRET->value);

        parent::tearDown();
    }

    public function testBrushCrazyRefundIsRefundedOnceThroughTheProcessor(): void
    {
        $order = $this->paidOrder(74.20);
        $payment = $this->payment($order, 74.20);
        $this->pending($order, 'sale:200', 1500);

        $entry = new RefundOrderFromBrushCrazyAction($order, 'sale:200')->execute();
        $again = new RefundOrderFromBrushCrazyAction($order, 'sale:200')->execute();

        $this->assertSame([15.0], $this->processorCalls->getArrayCopy());
        $this->assertNull($again);
        $this->assertSame(RefundLedger::REFUNDED, $entry['status']);
        $this->assertSame(1500, $entry['amount_cents']);
        $this->assertSame([(int) $payment->refunds()->value('id')], $entry['payment_refund_ids']);
        $this->assertSame(RefundLedger::REFUNDED, RefundLedger::of($order->fresh())['sale:200']['status']);
    }

    public function testNeverRefundsMoreThanKanvasStillHolds(): void
    {
        $order = $this->paidOrder(74.20);
        $payment = $this->payment($order, 74.20);
        $this->completedRefund($payment, 70.00, 'Refunded in Kanvas');
        $this->pending($order, 'full:5', 7420);

        $entry = new RefundOrderFromBrushCrazyAction($order, 'full:5')->execute();

        $this->assertSame([4.2], $this->processorCalls->getArrayCopy());
        $this->assertSame(420, $entry['amount_cents']);
        $this->assertSame(RefundLedger::REFUNDED, $entry['status']);
    }

    public function testProcessorKanvasCannotRefundThroughIsLeftForAManualRefund(): void
    {
        $order = $this->paidOrder(74.20);
        $this->payment($order, 74.20, processor: 'authorizenet-not-registered');
        $this->pending($order, 'sale:200', 1500);

        $entry = new RefundOrderFromBrushCrazyAction($order, 'sale:200')->execute();

        $this->assertSame(RefundLedger::MANUAL, $entry['status']);
        $this->assertSame(0, $entry['amount_cents']);
        $this->assertStringContainsString('by hand', $entry['message']);
        $this->assertSame([], $this->processorCalls->getArrayCopy());
    }

    public function testKanvasRefundIsPushedOnce(): void
    {
        $this->configureWriteBack();
        Http::fake([self::API_URL . '/api/kanvas/refunds' => Http::response(['status' => 'refunded', 'refund_sale_id' => 321])]);
        $order = $this->paidOrder(74.20, pushed: true);
        $refund = $this->completedRefund($this->payment($order, 74.20), 10.00, 'Customer asked');

        $first = $this->pushRefund($order, $refund);
        $second = $this->pushRefund($order, $refund);

        $this->assertSame('Refund recorded in BrushCrazy.', $first['message']);
        $this->assertStringContainsString('already recorded', $second['message']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->data()['kanvas_order_uuid'] === $order->uuid
            && $request->data()['kanvas_refund_id'] === $refund->uuid
            && $request->data()['amount_cents'] === 1000
            && $request->data()['full'] === false);
        $this->assertSame(RefundLedger::PUSHED, RefundLedger::of($order->fresh())['kanvas:' . $refund->uuid]['status']);
    }

    public function testRefundTheMirrorJobMadeIsNotPushedBack(): void
    {
        $this->configureWriteBack();
        Http::fake();
        $order = $this->paidOrder(74.20, pushed: true);
        $refund = $this->completedRefund($this->payment($order, 74.20), 15.00, RefundLedger::REASON_PREFIX . 'sale:200');

        $result = $this->pushRefund($order, $refund);

        $this->assertStringContainsString('because BrushCrazy refunded it', $result['message']);
        Http::assertNothingSent();
    }

    /** Processors that keep no reason are matched by the refund id the job wrote in the ledger. */
    public function testRefundTheLedgerNamesIsNotPushedBack(): void
    {
        $this->configureWriteBack();
        Http::fake();
        $order = $this->paidOrder(74.20, pushed: true);
        $refund = $this->completedRefund($this->payment($order, 74.20), 15.00, null);
        RefundLedger::update($order, fn (array $ledger) => $ledger + ['sale:200' => [
            'origin' => RefundLedger::ORIGIN_BRUSHCRAZY,
            'status' => RefundLedger::REFUNDED,
            'cents' => 1500,
            'amount_cents' => 1500,
            'payment_refund_ids' => [$refund->getId()],
        ]]);

        $this->pushRefund($order, $refund);

        Http::assertNothingSent();
    }

    private function pushRefund(Order $order, PaymentRefund $refund): array
    {
        $activity = new class () extends PushRefundToBrushCrazyActivity {
            public function __construct()
            {
            }

            public function workflowId()
            {
                return null;
            }
        };

        return $activity->execute($order, app(Apps::class), ['payment_refund_id' => $refund->getId()]);
    }

    private function pending(Order $order, string $key, int $cents): void
    {
        RefundLedger::update($order, fn (array $ledger) => $ledger + [$key => [
            'origin' => RefundLedger::ORIGIN_BRUSHCRAZY,
            'status' => RefundLedger::PENDING,
            'kind' => str_starts_with($key, 'full:') ? 'full' : 'amount',
            'cents' => $cents,
        ]]);
    }

    private function paidOrder(float $total, bool $pushed = false): Order
    {
        $order = $this->quietOrder($this->company->getId(), [
            'total_gross_amount' => $total,
            'total_net_amount' => $total,
            'payment_status' => PaymentStatusEnum::PAID->value,
            'metadata' => ['schema' => 'brushcrazy.event-order.v1'],
        ]);

        if ($pushed) {
            $order->disableWorkflows();
            $order->set(CustomFieldEnum::BRUSHCRAZY_SALE_ID->value, random_int(10_000_000, 99_999_999));
            $order->set(CustomFieldEnum::BRUSHCRAZY_ORIGIN->value, SyncSaleFromBrushCrazyAction::ORIGIN_KANVAS);
        }

        return $order;
    }

    private function payment(Order $order, float $amount, string $processor = self::PROCESSOR): Payments
    {
        $payment = new Payments();
        $payment->apps_id = app(Apps::class)->getId();
        $payment->companies_id = $this->company->getId();
        $payment->users_id = auth()->user()->getId();
        $payment->payment_methods_id = 0;
        $payment->payable_id = $order->getId();
        $payment->payable_type = $order->getMorphClass();
        $payment->concept = 'BrushCrazy refund test';
        $payment->payment_date = now()->toDateString();
        $payment->amount = $amount;
        $payment->currency = 'USD';
        $payment->status = PaymentStatusEnum::PAID->value;
        $payment->processor = $processor;
        $payment->payment_intent_id = 'pi_' . uniqid();
        $payment->saveOrFail();

        return $payment;
    }

    private function completedRefund(Payments $payment, float $amount, ?string $reason): PaymentRefund
    {
        $refund = new PaymentRefund();
        $refund->apps_id = $payment->apps_id;
        $refund->companies_id = $payment->companies_id;
        $refund->payments_id = $payment->getId();
        $refund->users_id = $payment->users_id;
        $refund->amount = $amount;
        $refund->currency = 'USD';
        $refund->reason = $reason;
        $refund->processor_refund_id = 're_' . uniqid();
        $refund->status = RefundStatusEnum::COMPLETED->value;
        $refund->saveOrFail();

        return $refund;
    }

    /**
     * Writes the refund row a real processor writes, without markAsCompleted(): that fires the
     * after-refund workflow, which these tests drive by hand.
     */
    private function fakeProcessor(): PaymentProcessorInterface
    {
        return new class ($this->processorCalls) implements PaymentProcessorInterface {
            public function __construct(private ArrayObject $calls)
            {
            }

            public function name(): string
            {
                return 'bc-refund-test';
            }

            public function authorize(Payments $payment, Order $order, array $context = []): AuthorizeResult
            {
                throw new DomainException('Not used.');
            }

            public function capture(Payments $payment, Order $order, ?float $amount = null, array $context = []): CaptureResult
            {
                throw new DomainException('Not used.');
            }

            public function refund(Payments $payment, Order $order, ?float $amount = null, array $context = []): RefundResult
            {
                $this->calls[] = $amount;
                $reference = 're_' . uniqid();

                $refund = new PaymentRefund();
                $refund->apps_id = $payment->apps_id;
                $refund->companies_id = $payment->companies_id;
                $refund->payments_id = $payment->getId();
                $refund->users_id = $payment->users_id;
                $refund->amount = $amount;
                $refund->currency = 'USD';
                $refund->reason = $context['reason'] ?? null;
                $refund->processor_refund_id = $reference;
                $refund->status = RefundStatusEnum::COMPLETED->value;
                $refund->saveOrFail();

                return new RefundResult(success: true, message: 'ok', transactionId: $reference);
            }

            public function void(Payments $payment, Order $order, array $context = []): VoidResult
            {
                throw new DomainException('Not used.');
            }

            public function verify(Payments $payment, Order $order): VerifyResult
            {
                throw new DomainException('Not used.');
            }
        };
    }

    private function configureWriteBack(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::BRUSHCRAZY_API_URL->value, self::API_URL);
        $app->set(ConfigurationEnum::BRUSHCRAZY_API_SECRET->value, self::SECRET);

        $integration = Integrations::firstOrCreate(
            ['name' => IntegrationsEnum::BRUSHCRAZY->value],
            ['apps_id' => 0, 'config' => [], 'handler' => 'Kanvas\\Connectors\\BrushCrazy\\Handlers\\BrushCrazyHandler'],
        );
        $region = Regions::getDefault($this->company, $app) ?? Regions::create([
            'apps_id' => $app->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => 0,
            'name' => 'Region ' . uniqid(),
            'is_default' => 1,
            'is_deleted' => 0,
        ]);

        IntegrationsCompany::firstOrCreate(
            [
                'companies_id' => $this->company->getId(),
                'integrations_id' => $integration->getId(),
                'region_id' => $region->getId(),
            ],
            [
                'status_id' => Status::where('slug', StatusEnum::ACTIVE->value)->where('apps_id', 0)->firstOrFail()->getId(),
                'is_active' => 1,
            ]
        );
    }
}
