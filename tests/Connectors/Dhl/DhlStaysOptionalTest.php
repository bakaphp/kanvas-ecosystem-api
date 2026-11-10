<?php

declare(strict_types=1);

namespace Tests\Connectors\Dhl;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Dhl\Activities\CreateDhlShipmentActivity;
use Kanvas\Connectors\Dhl\Enums\CustomFieldEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Shipping\DataTransferObject\ShippingQuote;
use Kanvas\Souk\Shipping\Providers\ShippingProviderFactory;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use PHPUnit\Framework\Attributes\Group;
use Tests\Connectors\Traits\CreatesDhlCabinetOrder;
use Tests\TestCase;

#[Group('serial')]
final class DhlStaysOptionalTest extends TestCase
{
    use CreatesDhlCabinetOrder;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'commerce', 'inventory', 'crm', 'ecosystem'];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Notification::fake();
    }

    public function testACompanyWithoutDhlGetsNoDhlProvider(): void
    {
        $company = Companies::factory()->create();

        $this->assertSame([], ShippingProviderFactory::forCompany(app(Apps::class), $company));
        Http::assertNothingSent();
    }

    public function testAQuoteWithoutBookingMetaIsUnchanged(): void
    {
        $quote = new ShippingQuote(
            provider: 'inposdom',
            serviceCode: 'ems',
            serviceName: 'EMS',
            amount: 10.0,
            currency: 'DOP',
            meta: ['zone' => 'z1'],
        );

        $this->assertSame([], $quote->bookingMeta);
        $this->assertSame(['zone' => 'z1'], $quote->meta);
    }

    public function testANonDhlOrderIsNeverBookedAndTheCompanyHasNoDhlIntegration(): void
    {
        $company = Companies::factory()->create();
        $this->setUpCabinetStatuses();
        $order = $this->cabinetOrder($company, 'ready-for-dispatch', ['metadata' => ['shipping' => ['provider' => 'inposdom', 'service' => 'ems']]]);

        $result = new CreateDhlShipmentActivity(0, now()->toDateTimeString(), StoredWorkflow::make(), [])->execute(
            $order,
            app(Apps::class),
            ['currentEventTypeName' => WorkflowEnum::STATUS_TRANSITION->value, 'to_status' => 'ready-for-dispatch']
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertNull($order->get(CustomFieldEnum::BOOKING_STATE->value));
        $this->assertSame('ready-for-dispatch', $this->statusSlugOf($order));
        Http::assertNothingSent();
    }

    public function testTheScheduledCommandsDoNothingWithoutADhlIntegration(): void
    {
        $this->artisan('kanvas:dhl-poll-tracking')->assertSuccessful();
        $this->artisan('kanvas:dhl-alert-unverified-orders')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, Order::query()->whereNotNull('tracking_client_id')->where('tracking_client_id', 'like', 'DHL%')->count());
    }
}
