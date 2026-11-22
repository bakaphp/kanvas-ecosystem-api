<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass\Parking;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Parking\Contracts\ParkingOperator;
use Kanvas\Connectors\Movipass\Parking\ParkingOperatorResolver;
use Kanvas\Connectors\Movipass\Parking\SimulatedParkingOperator;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;
use stdClass;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class ParkingOperatorTest extends TestCase
{
    use InventoryCases;

    private const string ENTRY = '2026-11-22T10:00:00+00:00';

    private Products $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Products::find($this->createProduct()->json('data.createProduct.id'));
        $this->setHourlyRate(0.75);
        Carbon::setTestNow(Carbon::parse(self::ENTRY));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function setHourlyRate(mixed $rate): void
    {
        $warehouseRow = $this->product->variants()->firstOrFail()->variantWarehouses()->firstOrFail();
        $warehouseRow->config = ['rates' => ['hourly' => $rate]];
        $warehouseRow->save();
    }

    private function parkingOrder(): Order
    {
        $variant = $this->product->variants()->firstOrFail();
        $variant->setRelation('product', $this->product);

        $item = new OrderItem();
        $item->setRelation('variant', $variant);

        $order = new Order();
        $order->id = 987654;
        $order->metadata = ['data' => ['parking' => ['entry_at' => self::ENTRY]]];
        $order->setRelation('items', collect([$item]));

        return $order;
    }

    private function quoteAfter(int $minutes): float
    {
        return new SimulatedParkingOperator()
            ->quote($this->parkingOrder(), Carbon::parse(self::ENTRY)->addMinutes($minutes))
            ->amount;
    }

    public function testSixtyMinutesBillsOneStartedHour(): void
    {
        $this->assertSame(0.75, $this->quoteAfter(60));
    }

    public function testSixtyOneMinutesBillsTwoStartedHours(): void
    {
        $this->assertSame(1.5, $this->quoteAfter(61));
    }

    public function testZeroMinutesBillsOneStartedHour(): void
    {
        $this->assertSame(0.75, $this->quoteAfter(0));
    }

    public function testClockOffsetFastForwardsTheQuote(): void
    {
        $this->product->set(SimulatedParkingOperator::CLOCK_OFFSET_SETTING, 120);

        $quote = new SimulatedParkingOperator()->quote($this->parkingOrder(), Carbon::parse(self::ENTRY)->addMinutes(10));

        $this->assertSame(2.25, $quote->amount);
        $this->assertTrue($quote->quotedAt->equalTo(Carbon::parse(self::ENTRY)->addMinutes(130)));
    }

    public function testReferenceIsDeterministicPerOrderAndQuoteTime(): void
    {
        $at = Carbon::parse(self::ENTRY)->addMinutes(30);
        $operator = new SimulatedParkingOperator();

        $this->assertSame(
            $operator->quote($this->parkingOrder(), $at)->reference,
            $operator->quote($this->parkingOrder(), $at)->reference
        );
        $this->assertSame('SIM-987654-' . $at->getTimestamp(), $operator->quote($this->parkingOrder(), $at)->reference);
    }

    public function testConfirmationAndExitAreAuthorizedByDefault(): void
    {
        $operator = new SimulatedParkingOperator();

        $this->assertTrue($operator->confirmPayment($this->parkingOrder(), 'SIM-1'));
        $this->assertTrue($operator->authorizeExit($this->parkingOrder()));
    }

    public function testForcedOutcomeDeniesConfirmation(): void
    {
        $this->product->set(SimulatedParkingOperator::FORCED_OUTCOME_SETTING, SimulatedParkingOperator::DENY_CONFIRMATION);
        $operator = new SimulatedParkingOperator();

        $this->assertFalse($operator->confirmPayment($this->parkingOrder(), 'SIM-1'));
        $this->assertTrue($operator->authorizeExit($this->parkingOrder()));
    }

    public function testForcedOutcomeDeniesExit(): void
    {
        $this->product->set(SimulatedParkingOperator::FORCED_OUTCOME_SETTING, SimulatedParkingOperator::DENY_EXIT);
        $operator = new SimulatedParkingOperator();

        $this->assertTrue($operator->confirmPayment($this->parkingOrder(), 'SIM-1'));
        $this->assertFalse($operator->authorizeExit($this->parkingOrder()));
    }

    public function testResolverDefaultsToTheSimulator(): void
    {
        $this->assertInstanceOf(SimulatedParkingOperator::class, ParkingOperatorResolver::forOrder($this->parkingOrder()));
    }

    public function testResolverRejectsClassesThatDoNotImplementTheContract(): void
    {
        $this->product->set(ParkingOperatorResolver::PRODUCT_SETTING, stdClass::class);

        $this->expectException(ValidationException::class);

        ParkingOperatorResolver::forProduct($this->product);
    }

    public function testResolverRejectsUnknownClasses(): void
    {
        $this->product->set(ParkingOperatorResolver::PRODUCT_SETTING, 'Not\A\RealOperator');

        $this->expectException(ValidationException::class);

        ParkingOperatorResolver::forProduct($this->product);
    }

    public function testResolverBuildsAConfiguredOperator(): void
    {
        $this->product->set(ParkingOperatorResolver::PRODUCT_SETTING, SimulatedParkingOperator::class);

        $this->assertInstanceOf(ParkingOperator::class, ParkingOperatorResolver::forProduct($this->product));
    }

    public function testForcedDenyAllDeniesConfirmationAndExit(): void
    {
        $this->product->set(SimulatedParkingOperator::FORCED_OUTCOME_SETTING, SimulatedParkingOperator::DENY_ALL);
        $operator = new SimulatedParkingOperator();

        $this->assertFalse($operator->confirmPayment($this->parkingOrder(), 'SIM-1'));
        $this->assertFalse($operator->authorizeExit($this->parkingOrder()));
    }

    public function testMissingEntryTimeThrows(): void
    {
        $order = $this->parkingOrder();
        $order->metadata = ['data' => ['parking' => []]];

        $this->expectException(ValidationException::class);

        new SimulatedParkingOperator()->quote($order, Carbon::parse(self::ENTRY)->addMinutes(30));
    }

    public function testMissingHourlyRateThrows(): void
    {
        $this->setHourlyRate(null);

        $this->expectException(ValidationException::class);

        $this->quoteAfter(30);
    }

    public function testNonNumericHourlyRateThrows(): void
    {
        $this->setHourlyRate('free');

        $this->expectException(ValidationException::class);

        $this->quoteAfter(30);
    }
}
