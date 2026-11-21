<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Apps;

use App\Http\Middleware\KanvasAppKeyMiddleware;
use Baka\Support\SentryUrlRedactor;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Actions\CreateAppKeyAction;
use Kanvas\Apps\Actions\RecordAppKeySourceAction;
use Kanvas\Apps\DataTransferObject\AppKeyInput;
use Kanvas\Apps\Models\AppKey;
use Kanvas\Apps\Models\AppKeySource;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Notifications\AppKeyNewSourceNotification;
use Kanvas\Auth\Actions\RegisterUsersAction;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Enums\AppEnums;
use RuntimeException;
use Sentry\Breadcrumb;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\Serializer\PayloadSerializer;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class AppKeySourceTrackingTest extends TestCase
{
    private Apps $currentApp;
    private AppKey $appKey;
    private ?HubInterface $originalHub = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);

        $keyOwner = new RegisterUsersAction(
            RegisterInput::from([
                'email' => fake()->unique()->safeEmail(),
                'password' => fake()->password(12),
                'firstname' => fake()->firstName(),
                'lastname' => fake()->lastName(),
            ])
        )->execute();

        $this->appKey = new CreateAppKeyAction(
            new AppKeyInput(
                'source-test-' . fake()->unique()->uuid(),
                $this->currentApp,
                $keyOwner
            ),
            createUserInApp: false
        )->execute();
    }

    protected function tearDown(): void
    {
        AppKeySource::query()->where('client_id', $this->appKey->client_id)->delete();

        if ($this->originalHub !== null) {
            SentrySdk::setCurrentHub($this->originalHub);
        }

        parent::tearDown();
    }

    private function sources(): int
    {
        return AppKeySource::query()->where('client_id', $this->appKey->client_id)->count();
    }

    private function source(): AppKeySource
    {
        return AppKeySource::query()->where('client_id', $this->appKey->client_id)->sole();
    }

    private function keyedRequest(string $forwardedFor): Request
    {
        $request = Request::create('/graphql', 'POST');
        $request->headers->set(AppEnums::KANVAS_APP_KEY_HEADER->getValue(), $this->appKey->client_secret_id);
        $request->headers->set('X-Forwarded-For', $forwardedFor);
        $request->headers->set('User-Agent', 'curl/8.22.0');

        return $request;
    }

    private function watch(): AppKey
    {
        $this->artisan('kanvas:app-key-watch', [
            'app_id' => $this->currentApp->getId(),
            '--key' => $this->appKey->client_id,
        ])->assertExitCode(0);

        return $this->appKey->refresh();
    }

    public function testMiddlewareRecordsTheSourceOfAKeyedRequest(): void
    {
        new KanvasAppKeyMiddleware()->handle($this->keyedRequest('203.0.113.7'), fn () => new Response());

        $source = $this->source();

        $this->assertSame('203.0.113.7', $source->ip);
        $this->assertSame('curl/8.22.0', $source->user_agent);
        $this->assertSame((int) $this->currentApp->getId(), (int) $source->apps_id);
    }

    public function testAForwardedHeaderThatResolvesToNothingIsStillTracked(): void
    {
        new KanvasAppKeyMiddleware()->handle($this->keyedRequest(', 203.0.113.9'), fn () => new Response());

        $this->assertSame('unknown', $this->source()->ip);
    }

    public function testAKnownSourceIsNotWrittenAgainWithinTheDay(): void
    {
        new RecordAppKeySourceAction($this->appKey, '203.0.113.8', 'first')->execute();
        new RecordAppKeySourceAction($this->appKey, '203.0.113.8', 'second')->execute();

        $this->assertSame(1, $this->sources());
        $this->assertSame('first', $this->source()->user_agent);
    }

    public function testAKnownSourceIsRefreshedOnceTheDayGuardExpires(): void
    {
        $action = new RecordAppKeySourceAction($this->appKey, '203.0.113.9', 'first');
        $action->execute();
        Cache::forget($action->seenKey());
        new RecordAppKeySourceAction($this->appKey, '203.0.113.9', 'second')->execute();

        $this->assertSame(1, $this->sources());
        $this->assertSame('second', $this->source()->user_agent);
    }

    public function testAFailedWriteIsRetriedInMinutesNotHiddenForADay(): void
    {
        $failing = true;
        AppKeySource::creating(function () use (&$failing): void {
            if ($failing) {
                throw new RuntimeException('database is away');
            }
        });

        $action = new RecordAppKeySourceAction($this->appKey, '203.0.113.12');
        $action->execute();

        $this->assertSame(0, $this->sources());
        $this->assertFalse(Cache::has($action->seenKey()));
        $this->assertTrue(Cache::has($action->retryKey()));

        $failing = false;
        Cache::forget($action->retryKey());
        $action->execute();

        $this->assertSame(1, $this->sources());
        $this->assertTrue(Cache::has($action->seenKey()));
    }

    public function testANewSourceAlertsOnlyWhenTheKeyIsWatched(): void
    {
        Notification::fake();
        config(['kanvas.capability_gap.alert_emails' => 'owner@example.com, second@example.com']);
        $events = $this->captureSentryEvents();

        new RecordAppKeySourceAction($this->appKey, '203.0.113.10')->execute();
        $this->assertCount(0, $events);

        $watched = $this->watch();
        $this->assertTrue($watched->alert_on_new_source);

        new RecordAppKeySourceAction($watched, '203.0.113.10')->execute();
        $this->assertCount(0, $events, 'a source already on file is not new');
        Notification::assertNothingSent();

        new RecordAppKeySourceAction($watched, '203.0.113.11')->execute();
        $this->assertCount(1, $events);
        $this->assertStringContainsString('203.0.113.11', (string) $events->first()->getMessage());

        Notification::assertSentOnDemand(
            AppKeyNewSourceNotification::class,
            function (
                AppKeyNewSourceNotification $notification,
                array $channels,
                AnonymousNotifiable $notifiable
            ) use ($watched): bool {
                $mail = $notification->toMail($notifiable);

                return $notifiable->routes['mail'] === ['owner@example.com', 'second@example.com']
                    && str_contains($mail->subject, '203.0.113.11')
                    && ! str_contains(implode(' ', $mail->introLines), $watched->client_secret_id);
            }
        );
    }

    public function testAnAlertThatCouldNotBeSentIsSentOnTheNextAttempt(): void
    {
        config(['kanvas.capability_gap.alert_emails' => 'owner@example.com']);
        $events = $this->captureSentryEvents();
        $watched = $this->watch();

        Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('queue is away'));

        $action = new RecordAppKeySourceAction($watched, '203.0.113.13');
        $action->execute();

        $this->assertTrue($this->source()->email_alert_pending);
        $this->assertFalse($this->source()->sentry_alert_pending, 'the channel that went out is not owed again');
        $this->assertFalse(Cache::has($action->seenKey()));

        Notification::fake();
        Cache::forget($action->retryKey());
        $action->execute();

        Notification::assertSentOnDemand(AppKeyNewSourceNotification::class);
        $this->assertCount(
            1,
            $events->filter(fn (Event $event) => str_contains((string) $event->getMessage(), 'new source')),
            'the retry does not repeat the Sentry alert'
        );
        $this->assertFalse($this->source()->hasPendingAlert());
        $this->assertTrue(Cache::has($action->seenKey()));
    }

    public function testASentryEventThatWasNotAcceptedStaysOwed(): void
    {
        Notification::fake();
        $this->captureSentryEvents(accepts: false);
        $watched = $this->watch();

        $action = new RecordAppKeySourceAction($watched, '203.0.113.15');
        $action->execute();

        $this->assertTrue($this->source()->sentry_alert_pending);
        $this->assertFalse($this->source()->email_alert_pending);
        $this->assertFalse(Cache::has($action->seenKey()));
    }

    public function testAnAlertOwedByASourceThatNeverReturnsIsDeliveredByTheScheduledCommand(): void
    {
        Notification::fake();
        config(['kanvas.capability_gap.alert_emails' => 'owner@example.com']);
        $this->captureSentryEvents(accepts: false);
        $watched = $this->watch();

        new RecordAppKeySourceAction($watched, '203.0.113.16')->execute();
        $this->assertTrue($this->source()->sentry_alert_pending);
        Notification::assertSentOnDemandTimes(AppKeyNewSourceNotification::class, 1);

        $this->source()->update(['last_seen_at' => now()->subDays(AppKeySource::RETENTION_DAYS + 1)]);
        $this->artisan('model:prune', ['--model' => [AppKeySource::class]])->assertExitCode(0);
        $this->assertSame(1, $this->sources(), 'a source still owed an alert is not pruned');

        $events = $this->captureSentryEvents();
        $this->artisan('kanvas:app-key-alert-pending-sources')->assertExitCode(0);

        $this->assertCount(1, $events);
        $this->assertFalse($this->source()->hasPendingAlert());
        Notification::assertSentOnDemandTimes(AppKeyNewSourceNotification::class, 1);
        // still one: the email went out on the first attempt and is not repeated
    }

    public function testAPendingAlertIsDroppedWhenTheKeyIsNoLongerWatched(): void
    {
        Notification::fake();
        $this->captureSentryEvents(accepts: false);

        new RecordAppKeySourceAction($this->watch(), '203.0.113.17')->execute();

        $this->artisan('kanvas:app-key-watch', [
            'app_id' => $this->currentApp->getId(),
            '--key' => $this->appKey->client_id,
            '--off' => true,
        ])->assertExitCode(0);

        $events = $this->captureSentryEvents();
        $this->artisan('kanvas:app-key-alert-pending-sources')->assertExitCode(0);

        $this->assertCount(0, $events);
        $this->assertFalse($this->source()->hasPendingAlert());
    }

    public function testTheSecretNeverReachesSentryInAnyPartOfTheEvent(): void
    {
        $events = $this->captureSentryEvents();
        $secret = $this->appKey->client_secret_id;
        $watched = $this->watch();

        // What the SDK attaches on a keyed request: the incoming headers and the key lookup query.
        SentrySdk::getCurrentHub()->configureScope(function (Scope $scope) use ($secret): void {
            $scope->addEventProcessor(fn (Event $event): Event => $event->setRequest([
                'url' => 'https://example.test/graphql',
                'method' => 'POST',
                'headers' => [AppEnums::KANVAS_APP_KEY_HEADER->getValue() => [$secret]],
            ]));
        });
        SentrySdk::getCurrentHub()->addBreadcrumb(new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_DEFAULT,
            'db.sql.query',
            'select * from `apps_keys` where `client_secret_id` = ? and `is_deleted` = ? limit 1',
            ['bindings' => [$secret, 0], 'connectionName' => 'ecosystem']
        ));

        new RecordAppKeySourceAction($watched, '203.0.113.14')->execute();

        $this->assertCount(1, $events);
        $payload = new PayloadSerializer(new Options())->serialize($events->first());
        $this->assertStringContainsString('db.sql.query', $payload);
        $this->assertStringContainsString('ecosystem', $payload);
        $this->assertStringNotContainsString($secret, $payload);
    }

    public function testSourcesNotSeenWithinTheRetentionWindowArePruned(): void
    {
        new RecordAppKeySourceAction($this->appKey, '203.0.113.20')->execute();
        new RecordAppKeySourceAction($this->appKey, '203.0.113.21')->execute();

        AppKeySource::query()
            ->where('client_id', $this->appKey->client_id)
            ->where('ip', '203.0.113.20')
            ->update(['last_seen_at' => now()->subDays(AppKeySource::RETENTION_DAYS + 1)]);

        $this->artisan('model:prune', ['--model' => [AppKeySource::class]])->assertExitCode(0);

        $this->assertSame(
            ['203.0.113.21'],
            AppKeySource::query()->where('client_id', $this->appKey->client_id)->pluck('ip')->all()
        );
    }

    /**
     * A real client behind a recording transport, so events pass through the same before_send and
     * before_breadcrumb hooks as production and the test does not depend on a DSN being configured.
     * `accepts: false` is Sentry being unreachable: the SDK swallows it and hands back no event id.
     *
     * @return Collection<int, Event>
     */
    private function captureSentryEvents(bool $accepts = true): Collection
    {
        $events = collect();

        $transport = new class ($events, $accepts) implements TransportInterface {
            public function __construct(private Collection $events, private bool $accepts)
            {
            }

            public function send(Event $event): Result
            {
                if (! $this->accepts) {
                    return new Result(ResultStatus::failed());
                }

                $this->events->push($event);

                return new Result(ResultStatus::success(), $event);
            }

            public function close(?int $timeout = null): Result
            {
                return new Result(ResultStatus::success());
            }
        };

        $this->originalHub ??= SentrySdk::getCurrentHub();

        SentrySdk::setCurrentHub(new Hub(
            ClientBuilder::create([
                'dsn' => 'https://public@example.test/1',
                'default_integrations' => false,
                'before_send' => [SentryUrlRedactor::class, 'event'],
                'before_breadcrumb' => [SentryUrlRedactor::class, 'breadcrumb'],
            ])->setTransport($transport)->getClient()
        ));

        return $events;
    }
}
