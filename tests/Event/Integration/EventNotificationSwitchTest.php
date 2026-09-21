<?php

declare(strict_types=1);

namespace Tests\Event\Integration;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Actions\CancelEventAction;
use Kanvas\Event\Events\Actions\CreateEventAction;
use Kanvas\Event\Events\Actions\UpdateEventAction;
use Kanvas\Event\Events\DataTransferObject\Event as EventDTO;
use Kanvas\Event\Events\Enums\ConfigurationEnum;
use Kanvas\Event\Events\Enums\EmailTemplateEnum;
use Kanvas\Event\Events\Enums\EventReminderStatusEnum;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Events\Notifications\EventParticipantNotification;
use Kanvas\Event\Support\Setup;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Tests\TestCase;

final class EventNotificationSwitchTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00', 'UTC'));
        Notification::fake();
    }

    public function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testEmailsAreOnUnlessTheAppTurnsThemOff(): void
    {
        $app = app(Apps::class);

        try {
            $app->del(ConfigurationEnum::SEND_EMAILS->value);
            $this->assertTrue(ConfigurationEnum::emailsEnabled($app));

            $app->set(ConfigurationEnum::SEND_EMAILS->value, 0);
            $this->assertFalse(ConfigurationEnum::emailsEnabled($app));

            $app->set(ConfigurationEnum::SEND_EMAILS->value, 'false');
            $this->assertFalse(ConfigurationEnum::emailsEnabled($app));

            $app->set(ConfigurationEnum::SEND_EMAILS->value, 1);
            $this->assertTrue(ConfigurationEnum::emailsEnabled($app));
        } finally {
            $app->del(ConfigurationEnum::SEND_EMAILS->value);
        }
    }

    public function testCreateEmailsParticipantsAndSchedulesAReminder(): void
    {
        $event = new CreateEventAction($this->buildEventDto())->disableWorkflow()->execute();
        $version = $event->versions()->first();

        Notification::assertSentOnDemand(
            EventParticipantNotification::class,
            fn (EventParticipantNotification $notification): bool => $notification->getTemplateName() === EmailTemplateEnum::BOOKING_CREATED->value
        );
        $this->assertSame(1, $version->reminders()->where('status', EventReminderStatusEnum::PENDING->value)->count());
    }

    public function testSyncCreateSendsNothingAndSchedulesNoReminder(): void
    {
        $event = new CreateEventAction($this->buildEventDto())
            ->disableWorkflow()
            ->withoutNotifications()
            ->execute();
        $version = $event->versions()->first();

        Notification::assertNothingSent();
        $this->assertSame(1, $version->participants()->count(), 'Participants are still synced, only the emails are skipped');
        $this->assertSame(0, $version->reminders()->count());
    }

    public function testAppWithEmailsOffSendsNothingEvenOnANormalCreate(): void
    {
        $app = app(Apps::class);

        try {
            $app->set(ConfigurationEnum::SEND_EMAILS->value, 0);

            $event = new CreateEventAction($this->buildEventDto())->disableWorkflow()->execute();

            Notification::assertNothingSent();
            $this->assertSame(0, $event->versions()->first()->reminders()->count());
        } finally {
            $app->del(ConfigurationEnum::SEND_EMAILS->value);
        }
    }

    public function testSyncUpdateDoesNotEmailButMovesTheExistingReminder(): void
    {
        $event = new CreateEventAction($this->buildEventDto())->disableWorkflow()->execute();
        $version = $event->versions()->first();
        Notification::fake();

        new UpdateEventAction($version, [
            'start_at' => Carbon::parse('2026-06-22 14:00:00', 'UTC'),
            'end_at' => Carbon::parse('2026-06-22 15:00:00', 'UTC'),
            'dates' => [
                [
                    'date' => '2026-06-22',
                    'start_time' => '14:00',
                    'end_time' => '15:00',
                ],
            ],
        ])->disableWorkflow()->withoutNotifications()->execute();

        Notification::assertNothingSent();

        $reminder = $version->reminders()->where('status', EventReminderStatusEnum::PENDING->value)->sole();
        $this->assertSame('2026-06-22T14:00:00+00:00', $reminder->metadata['start_at']);
    }

    public function testSyncUpdateOfAnEventWithoutRemindersCreatesNone(): void
    {
        $event = new CreateEventAction($this->buildEventDto())
            ->disableWorkflow()
            ->withoutNotifications()
            ->execute();
        $version = $event->versions()->first();

        new UpdateEventAction($version, [
            'dates' => [
                [
                    'date' => '2026-06-22',
                    'start_time' => '14:00',
                    'end_time' => '15:00',
                ],
            ],
        ])->disableWorkflow()->withoutNotifications()->execute();

        Notification::assertNothingSent();
        $this->assertSame(0, $version->reminders()->count());
    }

    public function testSyncCancelDoesNotEmailButStillCancelsTheReminder(): void
    {
        $event = new CreateEventAction($this->buildEventDto())->disableWorkflow()->execute();
        $version = $event->versions()->first();
        Notification::fake();

        new CancelEventAction($version)->withoutNotifications()->execute();

        Notification::assertNothingSent();
        $this->assertSame(0, $version->reminders()->where('status', EventReminderStatusEnum::PENDING->value)->count());
    }

    private function buildEventDto(): EventDTO
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new Setup($app, $user, $company)->run();

        return EventDTO::fromMultiple($app, $user, $company, [
            'name' => 'Notification switch ' . fake()->uuid(),
            'category_id' => EventCategory::fromApp($app)->fromCompany($company)->first()->getId(),
            'type_id' => EventType::fromApp($app)->fromCompany($company)->first()->getId(),
            'start_at' => Carbon::parse('2026-06-20 10:00:00', 'UTC'),
            'end_at' => Carbon::parse('2026-06-20 11:00:00', 'UTC'),
            'dates' => [
                [
                    'date' => '2026-06-20',
                    'start_time' => '10:00',
                    'end_time' => '11:00',
                ],
            ],
            'participants' => [
                [
                    'firstname' => 'Switch',
                    'lastname' => 'Tester',
                    'contacts' => [
                        [
                            'contacts_types_id' => ContactTypeEnum::EMAIL->value,
                            'value' => 'switch-' . fake()->uuid() . '@example.com',
                        ],
                    ],
                ],
            ],
        ]);
    }
}
