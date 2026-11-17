<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\BrushCrazy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\BrushCrazy\Actions\SyncSaleFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Enums\ConfigurationEnum;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Support\BrushCrazyApi;
use Kanvas\Connectors\BrushCrazy\Workflows\Activities\BrushCrazyPushActivity;
use Kanvas\Connectors\BrushCrazy\Workflows\Activities\PushCancellationToBrushCrazyActivity;
use Kanvas\Connectors\BrushCrazy\Workflows\Activities\PushPaidOrderToBrushCrazyActivity;
use Kanvas\Connectors\BrushCrazy\Workflows\Activities\PushRsvpToBrushCrazyActivity;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionParticipant;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Traits\BuildsEventVersionFixtures;

/**
 * The write-back to BrushCrazy: a success stamps the links, rows the mirror created are never
 * pushed back, a 4xx ends the run FAILED without throwing and a 5xx throws so the job retries.
 */
#[Group('serial')]
final class BrushCrazyWriteBackActivitiesTest extends TestCase
{
    use BuildsEventVersionFixtures;
    use CreatesQuietOrders;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'commerce', 'event', 'crm', 'workflow', 'ecosystem'];

    /** A public literal: SafeUrl resolves the host, and a made-up name would not resolve. */
    private const string API_URL = 'https://93.184.216.34';
    private const string SECRET = 'test-secret';
    private const int STUDIO_ID = 7;
    private const int VARIANT_ID = 990001;

    private Companies $company;

    private mixed $previousStudioId = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['scout.driver' => 'null', 'scout.queue' => false]);

        $app = app(Apps::class);
        $this->company = auth()->user()->getCurrentCompany();
        $this->previousStudioId = $this->company->get(CustomFieldEnum::BRUSHCRAZY_STUDIO_ID->value);

        $app->set(ConfigurationEnum::BRUSHCRAZY_API_URL->value, self::API_URL);
        $app->set(ConfigurationEnum::BRUSHCRAZY_API_SECRET->value, self::SECRET);
        $this->company->set(CustomFieldEnum::BRUSHCRAZY_STUDIO_ID->value, self::STUDIO_ID);

        $this->activateIntegration();
    }

    /**
     * Settings and custom fields land in Redis first, and the rollback never reaches Redis.
     */
    protected function tearDown(): void
    {
        $app = app(Apps::class);
        $app->del(ConfigurationEnum::BRUSHCRAZY_API_URL->value);
        $app->del(ConfigurationEnum::BRUSHCRAZY_API_SECRET->value);

        $this->previousStudioId !== null
            ? $this->company->set(CustomFieldEnum::BRUSHCRAZY_STUDIO_ID->value, $this->previousStudioId)
            : $this->company->del(CustomFieldEnum::BRUSHCRAZY_STUDIO_ID->value);

        parent::tearDown();
    }

    public function testPaidOrderIsBookedSignedAndStamped(): void
    {
        Http::fake([self::API_URL . '/api/kanvas/sales' => Http::response(['sale_id' => 4321, 'user_id' => 88, 'registrations' => []])]);
        [$order] = $this->siteBooking(total: 70.0);

        $result = $this->push($this->paid(), $order);

        $this->assertSame(4321, $result['sale_id']);
        $this->assertSame(4321, (int) $order->get(CustomFieldEnum::BRUSHCRAZY_SALE_ID->value));
        $this->assertSame(SyncSaleFromBrushCrazyAction::ORIGIN_KANVAS, $order->get(CustomFieldEnum::BRUSHCRAZY_ORIGIN->value));
        $this->assertSame(88, (int) $order->people->get(CustomFieldEnum::BRUSHCRAZY_USER_ID->value));

        Http::assertSent(function (Request $request) use ($order) {
            $body = $request->data();
            $signature = BrushCrazyApi::signature($request->header('X-Kanvas-Timestamp')[0], $request->body(), self::SECRET);

            return $request->header('X-Kanvas-Signature')[0] === $signature
                && $body['kanvas_order_uuid'] === $order->uuid
                && $body['studio_id'] === self::STUDIO_ID
                && $body['totals']['subtotal_cents'] === 7000
                && $body['lines'][0]['calendarable_type'] === 'lesson'
                && $body['lines'][0]['calendarable_id'] === 55
                && $body['lines'][0]['quantity'] === 2
                && $body['lines'][0]['unit_cents'] === 3500
                && count($body['lines'][0]['attendees']) === 2;
        });
    }

    public function testMirroredOrderIsNeverPushedBack(): void
    {
        Http::fake();
        [$order] = $this->siteBooking(total: 70.0);
        $order->disableWorkflows();
        $order->set(CustomFieldEnum::BRUSHCRAZY_SALE_ID->value, 999);

        $result = $this->push($this->paid(), $order);

        $this->assertStringContainsString('mirror', $result['message']);
        Http::assertNothingSent();
    }

    public function testAnOrderTheSiteDidNotMakeFailsWithoutCallingBrushCrazy(): void
    {
        Http::fake();
        [$order] = $this->siteBooking(total: 70.0);
        $order->metadata = ['schema' => 'something-else'];
        $order->reference = null;
        $order->saveQuietly();

        $activity = $this->paid();
        $result = $this->push($activity, $order);

        $this->assertSame(StatusEnum::FAILED, $activity->workflowStatus());
        $this->assertArrayNotHasKey('trace', $result);
        Http::assertNothingSent();
    }

    public function testAValidationErrorFailsTheRunWithoutThrowing(): void
    {
        Http::fake([self::API_URL . '/*' => Http::response(['message' => 'Calendarable is not in this studio.'], 422)]);
        [$order] = $this->siteBooking(total: 70.0);

        $activity = $this->paid();
        $result = $this->push($activity, $order);

        $this->assertSame(422, $result['status']);
        $this->assertSame(StatusEnum::FAILED, $activity->workflowStatus());
        $this->assertNull($order->get(CustomFieldEnum::BRUSHCRAZY_SALE_ID->value));
    }

    public function testAServerErrorThrowsSoTheJobRetries(): void
    {
        Http::fake([self::API_URL . '/*' => Http::response('down', 503)]);
        [$order] = $this->siteBooking(total: 70.0);

        $this->expectException(RequestException::class);

        $this->push($this->paid(), $order);
    }

    public function testRsvpWaitsForEveryAttendeeThenPushesOnce(): void
    {
        Http::fake([self::API_URL . '/api/kanvas/rsvps' => Http::response(['sale_id' => 77, 'user_id' => 5, 'registrations' => []])]);
        [$order, $version, $participants] = $this->siteBooking(total: 0.0);

        $this->setState($participants[1], 'held');
        $waiting = $this->push($this->rsvp(), $version, $this->change('participant-updated', $participants[0]));

        $this->assertStringContainsString('Waiting', $waiting['message']);
        Http::assertNothingSent();

        $this->setState($participants[1], 'confirmed');
        $this->push($this->rsvp(), $version, $this->change('participant-updated', $participants[1]));

        $this->assertSame(77, (int) $order->get(CustomFieldEnum::BRUSHCRAZY_SALE_ID->value));
        Http::assertSent(fn (Request $request) => count($request->data()['attendees']) === 2
            && $request->data()['kanvas_order_uuid'] === $order->uuid);
    }

    public function testCancellationIsSentOnlyForAPushedConfirmedAttendee(): void
    {
        Http::fake([self::API_URL . '/api/kanvas/cancellations' => Http::response(['status' => 'needs_refund_review'])]);
        [$order, $version, $participants] = $this->siteBooking(total: 70.0);

        $this->setState($participants[0], 'held');
        $participants[0]->softDelete();
        $this->push($this->cancellation(), $version, $this->change('participant-removed', $participants[0]));
        Http::assertNothingSent();

        $this->setState($participants[1], 'confirmed');
        $order->disableWorkflows();
        $order->set(CustomFieldEnum::BRUSHCRAZY_SALE_ID->value, 4321);
        $order->set(CustomFieldEnum::BRUSHCRAZY_ORIGIN->value, SyncSaleFromBrushCrazyAction::ORIGIN_KANVAS);
        $participants[1]->softDelete();

        $result = $this->push($this->cancellation(), $version, $this->change('participant-removed', $participants[1]));

        $this->assertSame('needs_refund_review', $result['status']);
        Http::assertSent(fn (Request $request) => $request->data()['remaining_attendees'] === 0
            && $request->data()['calendarable_id'] === 55);
    }

    /**
     * @return array{0: Order, 1: EventVersion, 2: list<EventVersionParticipant>}
     */
    private function siteBooking(float $total): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $version = $this->createEventVersionWithParticipants(participantCount: 2);
        $version->disableWorkflows();
        $version->set(CustomFieldEnum::BRUSHCRAZY_CALENDARABLE_KEY->value, 'lesson:55');

        $order = $this->quietOrder(
            $this->company->getId(),
            [
                'reference' => 'bc-event:res-' . uniqid(),
                'user_email' => 'buyer-' . uniqid() . '@example.com',
                'total_gross_amount' => $total,
                'total_net_amount' => $total,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'metadata' => [
                    'schema' => 'brushcrazy.event-order.v1',
                    'event_version_id' => (string) $version->getId(),
                ],
            ],
            completed: false,
        );

        OrderItem::create([
            'order_id' => $order->getId(),
            'apps_id' => $app->getId(),
            'product_name' => 'Ticket',
            'product_sku' => 'BC-EVT-TEST',
            'variant_name' => 'Seat',
            'quantity' => 2,
            'unit_price_net_amount' => $total / 2,
            'unit_price_gross_amount' => $total / 2,
            'is_shipping_required' => 0,
            'quantity_fulfilled' => 0,
            'variant_id' => self::VARIANT_ID,
            'currency' => 'USD',
        ]);

        $participants = EventVersionParticipant::query()
            ->where('event_version_id', $version->getId())
            ->orderBy('id')
            ->get()
            ->values();

        foreach ($participants as $index => $participant) {
            $participant->metadata = [
                'schema' => 'brushcrazy.event-reservation.v1',
                'state' => 'confirmed',
                'reservation_key' => 'res-key',
                'attendee_index' => $index,
                'variant_id' => (string) self::VARIANT_ID,
                'order_id' => (string) $order->getId(),
                'order_uuid' => $order->uuid,
            ];
            $participant->saveQuietly();
        }

        return [$order->fresh(), $version, $participants->all()];
    }

    private function setState(EventVersionParticipant $participant, string $state): void
    {
        $participant->metadata = array_merge($participant->metadata, ['state' => $state]);
        $participant->saveQuietly();
    }

    private function change(string $change, EventVersionParticipant $participant): array
    {
        return ['event_version_change' => $change, 'event_version_participant_id' => $participant->getId()];
    }

    private function push(BrushCrazyPushActivity $activity, Model $entity, array $params = []): array
    {
        return $activity->execute($entity, app(Apps::class), $params);
    }

    /**
     * Activities are normally built by the workflow engine; these skip its constructor.
     */
    private function paid(): PushPaidOrderToBrushCrazyActivity
    {
        return new class () extends PushPaidOrderToBrushCrazyActivity {
            public function __construct()
            {
            }

            public function workflowId()
            {
                return null;
            }
        };
    }

    private function rsvp(): PushRsvpToBrushCrazyActivity
    {
        return new class () extends PushRsvpToBrushCrazyActivity {
            public function __construct()
            {
            }

            public function workflowId()
            {
                return null;
            }
        };
    }

    private function cancellation(): PushCancellationToBrushCrazyActivity
    {
        return new class () extends PushCancellationToBrushCrazyActivity {
            public function __construct()
            {
            }

            public function workflowId()
            {
                return null;
            }
        };
    }

    private function region(): Regions
    {
        $app = app(Apps::class);

        return Regions::getDefault($this->company, $app) ?? Regions::create([
            'apps_id' => $app->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => 0,
            'name' => 'Region ' . uniqid(),
            'is_default' => 1,
            'is_deleted' => 0,
        ]);
    }

    private function activateIntegration(): void
    {
        $integration = Integrations::firstOrCreate(
            ['name' => IntegrationsEnum::BRUSHCRAZY->value],
            ['apps_id' => 0, 'config' => [], 'handler' => 'Kanvas\\Connectors\\BrushCrazy\\Handlers\\BrushCrazyHandler'],
        );

        IntegrationsCompany::firstOrCreate(
            [
                'companies_id' => $this->company->getId(),
                'integrations_id' => $integration->getId(),
                'region_id' => $this->region()->getId(),
            ],
            [
                'status_id' => Status::where('slug', StatusEnum::ACTIVE->value)->where('apps_id', 0)->firstOrFail()->getId(),
                'is_active' => 1,
            ]
        );
    }
}
