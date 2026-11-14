<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Handlers\MovipassHandler;
use Kanvas\Connectors\Movipass\Workflows\Activities\SyncMovipassActivity;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Support\Setup;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\Event\Concerns\BuildsInviteOnlyParking;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

final class SyncMovipassEventBookingTest extends TestCase
{
    use BuildsInviteOnlyParking;
    use DatabaseTransactions;
    use HasIntegrationCompany;
    use InventoryCases;

    protected $apps;
    protected $user;
    protected $company;
    protected $region;
    protected array $days;

    protected function connectionsToTransact(): array
    {
        return [null, 'event', 'commerce'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Movipass integration tests are skipped in CI');
        }

        Notification::fake();

        $this->apps = app(Apps::class);
        $this->user = Auth::user();
        $this->company = $this->user->getCurrentCompany();
        $this->region = Regions::getDefault($this->company, $this->apps);
        $this->company->timezone = 'UTC';
        $this->company->saveOrFail();

        new Setup($this->apps, $this->user, $this->company)->run();
        $this->setIntegration($this->apps, IntegrationsEnum::MOVIPASS, MovipassHandler::class, $this->company, $this->user);

        $this->days = $this->buildInviteOnlyParking(days: 1, capacity: 5);
        $this->configureBookingOrderType('movipass');
        RateLimiter::clear('invite-code:user:' . $this->user->getId());
    }

    public function testCreatedEventBackedFreeOrderIsActivatedWithoutThePaymentQr(): void
    {
        $order = $this->bookedOrder();

        $result = $this->runActivity($order, WorkflowEnum::CREATED->value);

        $order->refresh();
        $this->assertSame('success', $result['status']);
        $this->assertSame('active', $order->orderStatus->slug);
        $this->assertArrayNotHasKey('qr_code', $order->metadata['data']);
        $this->assertArrayNotHasKey('qr_url', $order->metadata['data']);
    }

    public function testStatusTransitionsOfAnEventBackedOrderLeaveVariantCapacityAlone(): void
    {
        $this->gatedProduct->addAttribute('slots', 100);
        $this->gatedProduct->save();
        $variantWarehouse = $this->gatedVariant->variantWarehouses()->firstOrFail();
        $variantWarehouse->max_capacity = 100;
        $variantWarehouse->save();
        $order = $this->bookedOrder();

        $this->runActivity($order, WorkflowEnum::STATUS_TRANSITION->value, ['to_status' => 'ACTIVE']);

        $this->assertNull($this->gatedProduct->refresh()->getAttributeByName('capacity'));
    }

    private function bookedOrder(): Order
    {
        $code = $this->issueInvite($this->days[0]['version']);
        $response = $this->bookWithInvite($this->days[0]['slot'], $code, ['vehiclePlate' => 'A123456']);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));

        return EventVersion::findOrFail($response->json('data.bookTimeSlot.id'))->event->orders()->firstOrFail();
    }

    private function runActivity(Order $order, string $eventName, array $params = []): array
    {
        return new SyncMovipassActivity(0, now()->toDateTimeString(), new StoredWorkflow(), [])->execute(
            $order,
            $this->apps,
            [...$params, 'currentEventTypeName' => $eventName]
        );
    }
}
