<?php

declare(strict_types=1);

namespace Tests\GraphQL\Event;

use Illuminate\Support\Carbon;
use Kanvas\Event\Events\Enums\EmailTemplateEnum;
use Kanvas\Event\Events\Enums\EventReminderStatusEnum;
use Kanvas\Event\Events\Models\EventReminder;

class BookingReminderCommandTest extends ResourceBookingBase
{
    public function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testBookingCreatesPendingReminder(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-28 09:00:00'));

        $bookingData = $this->getBasicBookingData();
        $bookingData['start_at'] = '2026-03-29 10:00:00';
        $bookingData['end_at'] = '2026-03-29 12:00:00';

        $booking = $this->createBooking($bookingData);

        $reminder = EventReminder::where('event_version_id', $booking['id'])->first();

        $this->assertNotNull($reminder);
        $this->assertEquals(EmailTemplateEnum::BOOKING_REMINDER->value, $reminder->notification_type);
        $this->assertEquals(EventReminderStatusEnum::PENDING->value, $reminder->status);
        $this->assertEquals('2026-03-28 10:00:00', $reminder->send_at?->format('Y-m-d H:i:s'));
    }

    public function testUpdatingBookingReschedulesReminder(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-28 09:00:00'));

        $bookingData = $this->getBasicBookingData();
        $bookingData['start_at'] = '2026-03-29 10:00:00';
        $bookingData['end_at'] = '2026-03-29 12:00:00';

        $booking = $this->createBooking($bookingData);
        $originalReminder = EventReminder::where('event_version_id', $booking['id'])->firstOrFail();

        $response = $this->graphQL('
            mutation updateResourceBooking($input: ResourceBookingUpdateInput!) {
                updateResourceBooking(input: $input) {
                    id
                }
            }
        ', [
            'input' => [
                'event_version_id' => $booking['id'],
                'start_at' => '2026-03-30 14:00:00',
                'end_at' => '2026-03-30 16:00:00',
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $this->assertNull($response->json('errors'));

        $updatedReminder = EventReminder::where('event_version_id', $booking['id'])->firstOrFail()->fresh();

        $this->assertEquals($originalReminder->id, $updatedReminder->id);
        $this->assertEquals(EventReminderStatusEnum::PENDING->value, $updatedReminder->status);
        $this->assertEquals('2026-03-29 14:00:00', $updatedReminder->send_at?->format('Y-m-d H:i:s'));
    }

    public function testCancelingBookingCancelsReminder(): void
    {
        $booking = $this->createBooking($this->getBasicBookingData());

        $response = $this->graphQL('
            mutation deleteResourceBooking($eventVersionId: ID!) {
                deleteResourceBooking(event_version_id: $eventVersionId) {
                    success
                }
            }
        ', [
            'eventVersionId' => $booking['id'],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $this->assertNull($response->json('errors'));

        $reminder = EventReminder::where('event_version_id', $booking['id'])->firstOrFail()->fresh();

        $this->assertEquals(EventReminderStatusEnum::CANCELED->value, $reminder->status);
    }

    public function testSendBookingRemindersCommandMarksReminderAsSentOnce(): void
    {
        $booking = $this->createBooking($this->getBasicBookingData());
        $reminder = EventReminder::where('event_version_id', $booking['id'])->firstOrFail();

        $reminder->update([
            'send_at' => now()->subMinute(),
        ]);

        $this->artisan('kanvas:events:send-booking-reminders --limit=10')
            ->expectsOutput('Processed 1 booking reminders.')
            ->assertExitCode(0);

        $reminder->refresh();
        $firstSentAt = $reminder->sent_at;

        $this->assertEquals(EventReminderStatusEnum::SENT->value, $reminder->status);
        $this->assertNotNull($firstSentAt);

        $this->artisan('kanvas:events:send-booking-reminders --limit=10')
            ->expectsOutput('Processed 0 booking reminders.')
            ->assertExitCode(0);

        $reminder->refresh();

        $this->assertEquals(EventReminderStatusEnum::SENT->value, $reminder->status);
        $this->assertTrue($reminder->sent_at?->equalTo($firstSentAt));
        $this->assertEquals(1, EventReminder::where('event_version_id', $booking['id'])->count());
    }
}
