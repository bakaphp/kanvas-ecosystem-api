<?php

declare(strict_types=1);

namespace Tests\Connectors\Movipass;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EchoPay\DataTransferObject\ConsumerAuthentication;
use Kanvas\Connectors\Movipass\Actions\ApplyRoadsideQuoteToOrderAction;
use Kanvas\Connectors\Movipass\Actions\ProcessPaymentAction;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideServiceTypeEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Payments\Providers\PortalPaymentProcessor;
use Kanvas\Users\Models\Users;
use PHPUnit\Framework\Attributes\Group;
use Tests\Connectors\Movipass\Concerns\BuildsRoadsideIntake;
use Tests\Connectors\Movipass\Concerns\TogglesRegionalAssistance;
use Tests\TestCase;

#[Group('serial')]
final class RoadsidePricingTest extends TestCase
{
    use BuildsRoadsideIntake;
    use TogglesRegionalAssistance;

    // An off-peak, non-holiday jump start inside AMSS: 15.00 + 13% VAT.
    private const QUOTED_TOTAL = 16.95;
    private const CART_PRICE = 99.00;

    protected Apps $apps;
    protected Users $authUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apps = app(Apps::class);
        $this->authUser = auth()->user();

        $this->rememberRegionalAssistanceSettings($this->apps);
        $this->toggleRegionalAssistance($this->apps, true);
    }

    protected function tearDown(): void
    {
        $this->restoreRegionalAssistanceSettings($this->apps);

        parent::tearDown();
    }

    public function testOutsideRegionalAssistanceTheOrderKeepsTheCartPrice(): void
    {
        $this->toggleRegionalAssistance($this->apps, false);
        $order = $this->createRoadsideOrder();

        $this->assertNull(new ApplyRoadsideQuoteToOrderAction($order)->execute());

        $order->refresh();
        $this->assertSame(self::CART_PRICE, (float) $order->total_net_amount);
        $this->assertArrayNotHasKey('quote', $order->metadata['assistance_case']);
    }

    public function testOutsideRegionalAssistanceTheCartPricePaymentReachesTheProcessorUntouched(): void
    {
        $this->toggleRegionalAssistance($this->apps, false);
        $order = $this->createRoadsideOrder();
        $payment = $this->createPayment($order, self::CART_PRICE);

        $processor = $this->createMock(PortalPaymentProcessor::class);
        $processor->expects($this->once())
            ->method('processPayment')
            ->willReturn(['status' => 'error', 'message' => 'Card declined', 'data' => []]);

        $result = new ProcessPaymentAction($this->apps, $payment, $order, $processor)
            ->execute($this->createMock(ConsumerAuthentication::class));

        $this->assertSame('Card declined', $result['message']);
        $this->assertSame(self::CART_PRICE, (float) $order->refresh()->total_net_amount);
    }

    public function testTheOrderIsRepricedFromTheTariffSheetNotTheCart(): void
    {
        $order = $this->createRoadsideOrder();

        $quote = new ApplyRoadsideQuoteToOrderAction($order)->execute();

        $this->assertSame(self::QUOTED_TOTAL, $quote?->price->total);

        $order->refresh();
        $this->assertSame(self::QUOTED_TOTAL, (float) $order->total_net_amount);
        $this->assertSame(self::QUOTED_TOTAL, (float) $order->total_gross_amount);
        $this->assertSame(1.95, (float) $order->tax_amount);
        $this->assertSame(self::QUOTED_TOTAL, (float) $order->items()->first()->unit_price_net_amount);
        $this->assertSame(self::QUOTED_TOTAL, $order->metadata['assistance_case']['quote']['total']);
    }

    public function testRepricingIsDeterministicSoAForgedQuoteInMetadataIsOverwritten(): void
    {
        $order = $this->createRoadsideOrder(['quote' => ['total' => 0.01]]);

        new ApplyRoadsideQuoteToOrderAction($order)->execute();
        new ApplyRoadsideQuoteToOrderAction($order->refresh())->execute();

        $order->refresh();
        $this->assertSame(self::QUOTED_TOTAL, (float) $order->total_net_amount);
        $this->assertSame(self::QUOTED_TOTAL, $order->metadata['assistance_case']['quote']['total']);
    }

    public function testAPaidOrderKeepsThePriceItWasPaidAt(): void
    {
        $order = $this->createRoadsideOrder();
        $order->payment_status = PaymentStatusEnum::PAID->value;
        $order->saveQuietly();

        $this->assertNull(new ApplyRoadsideQuoteToOrderAction($order)->execute());
        $this->assertSame(self::CART_PRICE, (float) $order->refresh()->total_net_amount);
    }

    public function testAPaymentOpenedAtTheCartPriceIsRefusedBeforeTheCardIsTouched(): void
    {
        $order = $this->createRoadsideOrder();
        $payment = $this->createPayment($order, self::CART_PRICE);

        $processor = $this->createMock(PortalPaymentProcessor::class);
        $processor->expects($this->never())->method('processPayment');

        $result = new ProcessPaymentAction($this->apps, $payment, $order, $processor)
            ->execute($this->createMock(ConsumerAuthentication::class));

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('16.95', $result['message']);
        $this->assertSame(PaymentStatusEnum::FAILED->value, $payment->refresh()->status);
        $this->assertSame(self::QUOTED_TOTAL, (float) $order->refresh()->total_net_amount);
    }

    public function testAPaymentForTheQuotedTotalReachesTheProcessor(): void
    {
        $order = $this->createRoadsideOrder();
        $payment = $this->createPayment($order, self::QUOTED_TOTAL);

        $processor = $this->createMock(PortalPaymentProcessor::class);
        $processor->expects($this->once())
            ->method('processPayment')
            ->willReturn(['status' => 'error', 'message' => 'Card declined', 'data' => []]);

        $result = new ProcessPaymentAction($this->apps, $payment, $order, $processor)
            ->execute($this->createMock(ConsumerAuthentication::class));

        $this->assertSame('Card declined', $result['message']);
    }

    public function testAnIntakeThatDoesNotProceedCannotBePriced(): void
    {
        $order = $this->createRoadsideOrder([
            'intake' => $this->proceedingIntake(RoadsideServiceTypeEnum::JUMP_START, ['is_electric' => true]),
        ]);

        $this->expectExceptionMessage('electric_vehicle');

        new ApplyRoadsideQuoteToOrderAction($order)->execute();
    }

    public function testACabinReviewIntakeIsPricedAndKeepsTheVerdict(): void
    {
        $order = $this->createRoadsideOrder([
            'intake' => $this->proceedingIntake(RoadsideServiceTypeEnum::JUMP_START, ['single_battery' => false]),
        ]);

        new ApplyRoadsideQuoteToOrderAction($order)->execute();

        $this->assertSame('cabin_review', $order->refresh()->metadata['assistance_case']['intake_verdict']['outcome']);
    }

    public function testAPaymentWithoutAcceptedTermsIsRefusedBeforeTheCardIsTouched(): void
    {
        $order = $this->createRoadsideOrder(['terms_accepted' => false]);
        $payment = $this->createPayment($order, self::QUOTED_TOTAL);

        $processor = $this->createMock(PortalPaymentProcessor::class);
        $processor->expects($this->never())->method('processPayment');

        $result = new ProcessPaymentAction($this->apps, $payment, $order, $processor)
            ->execute($this->createMock(ConsumerAuthentication::class));

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('terms', $result['message']);
    }

    private function createRoadsideOrder(array $assistanceCase = []): Order
    {
        $orderType = OrderTypes::firstOrCreate([
            'name' => OrderTypeEnum::ROADSIDE_ASSISTANCE->value,
            'apps_id' => $this->apps->getId(),
        ]);

        $order = Order::factory()
            ->withCompanyId($this->authUser->getCurrentCompany()->getId())
            ->withUserId($this->authUser->getId())
            ->create([
                'order_types_id' => $orderType->getId(),
                'total_gross_amount' => self::CART_PRICE,
                'total_net_amount' => self::CART_PRICE,
                'metadata' => ['assistance_case' => [
                    'service' => 'Jump start',
                    'service_type' => 'jump_start',
                    'location' => ['lat' => 13.6929, 'lng' => -89.2182],
                    'intake' => $this->proceedingIntake(RoadsideServiceTypeEnum::JUMP_START),
                    'terms_accepted' => true,
                    ...$assistanceCase,
                ]],
            ]);

        // created_at decides the surcharge, so pin it to a plain Tuesday morning. Eloquent stores the
        // wall-clock digits without converting, so hand it the instant in the app's own timezone.
        $order->created_at = Carbon::parse('2026-10-20 10:00', 'America/El_Salvador')
            ->setTimezone(config('app.timezone'));
        $order->saveQuietly();

        $item = new OrderItem();
        $item->apps_id = $this->apps->getId();
        $item->order_id = $order->getId();
        $item->variant_id = 1;
        $item->variant_name = 'Jump start';
        $item->product_name = 'Roadside assistance';
        $item->product_sku = 'ROADSIDE-JUMP';
        $item->quantity = 1;
        $item->unit_price_net_amount = self::CART_PRICE;
        $item->unit_price_gross_amount = self::CART_PRICE;
        $item->quantity_fulfilled = 0;
        $item->currency = 'USD';
        $item->is_public = 1;
        $item->save();

        return $order->refresh();
    }

    private function createPayment(Order $order, float $amount): Payments
    {
        $payment = new Payments();
        $payment->apps_id = $this->apps->getId();
        $payment->companies_id = $order->companies_id;
        $payment->users_id = $this->authUser->getId();
        $payment->payment_methods_id = 0;
        $payment->payable_id = $order->getId();
        $payment->payable_type = Order::class;
        $payment->concept = 'Roadside pricing test';
        $payment->payment_date = now()->toDateString();
        $payment->amount = $amount;
        $payment->currency = 'USD';
        $payment->status = PaymentStatusEnum::PROCESSING->value;
        $payment->saveOrFail();

        return $payment;
    }
}
