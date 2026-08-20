<?php

declare(strict_types=1);

namespace Tests\Event\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Events\Models\TimeSlots;
use Kanvas\Event\Support\Setup;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class ParticipantStatsTest extends TestCase
{
    use DatabaseTransactions;
    use InventoryCases;

    protected $apps;
    protected $user;
    protected $company;
    protected $region;
    protected Variants $variant;

    private string $regularEmail;
    private string $newcomerEmail;

    /**
     * `time_slots` is unique on (resources_id, resources_type, start_at), so every booking of a
     * test needs its own tee time even when it lands on the same day.
     */
    private int $slotHour = 10;

    private string $statsQuery = '
        query participantStats($from: Date, $to: Date, $topN: Int) {
            participantStats(from_date: $from, to_date: $to, top_n: $topN) {
                total
                new_count
                returning_count
                rows {
                    participant_id
                    name
                    email
                    count
                    had_prior_activity
                }
            }
        }
    ';

    /**
     * `inventory` is deliberately NOT transacted. CreateProductAction leans on
     * `DB::connection('inventory')->transaction($cb, 3)` to retry the gap-lock deadlock that
     * concurrent product inserts hit, and Laravel can only retry a transaction it opened
     * itself — wrapping this connection here demotes that one to a savepoint and the
     * deadlock escapes as a 500 instead.
     */
    protected function connectionsToTransact(): array
    {
        return [null, 'event'];
    }

    public function setUp(): void
    {
        parent::setUp();

        // Booking a free tee time also fires the BOOKING_CREATED email, which needs the
        // `booking_created` template seeded. Faking keeps these tests off that dependency.
        Notification::fake();

        $this->apps = app(Apps::class);
        $this->user = Auth::user();
        $this->company = $this->user->getCurrentCompany();
        $this->region = Regions::getDefault($this->company, $this->apps);

        $this->regularEmail = 'regular-' . uniqid() . '@example.com';
        $this->newcomerEmail = 'newcomer-' . uniqid() . '@example.com';

        $warehouseResponse = $this->graphQLData($this->createWarehouses((string) $this->region->getId()), 'createWarehouse');
        $channelResponse = $this->graphQLData($this->createChannel(), 'createChannel');
        $productResponse = $this->graphQLData($this->createProduct(), 'createProduct');

        $this->variant = Products::find($productResponse['id'])->variants()->first();

        $this->graphQLData(
            $this->addVariantToChannel(
                variantId: (string) $this->variant->getId(),
                channelId: $channelResponse['id'],
                warehouseData: ['id' => $warehouseResponse['id']]
            ),
            'addVariantToChannel'
        );

        $this->graphQLData(
            $this->addVariantToWarehouse(
                variantId: (string) $this->variant->getId(),
                warehouseId: (string) $warehouseResponse['id'],
                amount: 10
            ),
            'addVariantToWarehouse'
        );

        $this->company->timezone = 'UTC';
        $this->company->saveOrFail();

        new Setup($this->apps, $this->user, $this->company)->run();
    }

    public function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testTheSamePlayerAccumulatesAcrossBookings(): void
    {
        $firstDay = Carbon::now()->addDays(2);
        $secondDay = Carbon::now()->addDays(3);

        $this->bookFor($this->regularEmail, $firstDay);
        $this->bookFor($this->regularEmail, $secondDay);
        $this->bookFor($this->newcomerEmail, $secondDay);

        $rows = collect($this->queryStats($firstDay, $secondDay)['rows'])->keyBy('email');

        $this->assertSame(2, $rows[$this->regularEmail]['count'], 'the same email must resolve to the same player across bookings');
        $this->assertSame(1, $rows[$this->newcomerEmail]['count']);
        $this->assertNotSame('', $rows[$this->regularEmail]['name']);
    }

    public function testRankingPutsTheMostFrequentPlayerFirst(): void
    {
        $firstDay = Carbon::now()->addDays(2);
        $secondDay = Carbon::now()->addDays(3);

        $this->bookFor($this->newcomerEmail, $firstDay);
        $this->bookFor($this->regularEmail, $firstDay);
        $this->bookFor($this->regularEmail, $secondDay);

        $rows = $this->queryStats($firstDay, $secondDay)['rows'];

        $this->assertSame($this->regularEmail, $rows[0]['email']);
        $this->assertSame(2, $rows[0]['count']);
    }

    public function testPlayerWhoStartedBeforeTheWindowCountsAsReturning(): void
    {
        $before = Carbon::now()->addDays(2);
        $windowStart = Carbon::now()->addDays(5);

        $this->bookFor($this->regularEmail, $before);
        $this->bookFor($this->regularEmail, $windowStart);
        $this->bookFor($this->newcomerEmail, $windowStart);

        $stats = $this->queryStats($windowStart, $windowStart);

        $rows = collect($stats['rows'])->keyBy('email');

        $this->assertTrue($rows[$this->regularEmail]['had_prior_activity']);
        $this->assertFalse($rows[$this->newcomerEmail]['had_prior_activity']);
        $this->assertSame(1, $stats['new_count']);
        $this->assertSame(1, $stats['returning_count']);
    }

    public function testTopNLimitsTheRowsWithoutChangingTheTotals(): void
    {
        $day = Carbon::now()->addDays(2);

        $this->bookFor($this->regularEmail, $day);
        $this->bookFor($this->newcomerEmail, $day);

        $stats = $this->queryStats($day, $day, topN: 1);

        $this->assertCount(1, $stats['rows']);
        $this->assertSame(2, $stats['total'], 'top_n trims the ranking, it must not trim the population');
    }

    private function bookFor(string $email, Carbon $day): void
    {
        $start = $day->copy()->setTime($this->slotHour++, 0, 0);

        $timeSlot = TimeSlots::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variant->getId(),
            'resources_type' => $this->variant->getMorphClass(),
            'start_at' => $start,
            'end_at' => $start->copy()->addHour(),
            'initial_capacity' => 4,
            'status' => 'open',
        ]);

        $response = $this->graphQL('
            mutation bookTimeSlot($input: TimeSlotBookingInput!) {
                bookTimeSlot(input: $input) { id }
            }
        ', [
            'input' => [
                'time_slot_id' => (string) $timeSlot->id,
                'event_name' => 'Activity booking ' . uniqid(),
                'participants' => [
                    [
                        'firstname' => 'Player',
                        'lastname' => 'One',
                        'contacts' => [
                            ['contacts_types_id' => 1, 'value' => $email, 'weight' => 1],
                        ],
                    ],
                ],
                'metadata' => [
                    'category_id' => EventCategory::fromCompany($this->company)->fromApp($this->apps)->first()->getId(),
                    'type_id' => EventType::fromCompany($this->company)->fromApp($this->apps)->first()->getId(),
                ],
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));
    }

    private function queryStats(Carbon $from, Carbon $to, ?int $topN = null): array
    {
        $response = $this->graphQL($this->statsQuery, [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'topN' => $topN,
        ]);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));

        return $response->json('data.participantStats');
    }
}
