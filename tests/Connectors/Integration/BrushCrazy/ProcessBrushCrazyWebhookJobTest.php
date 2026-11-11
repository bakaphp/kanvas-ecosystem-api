<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\BrushCrazy;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\BrushCrazy\Enums\ConfigurationEnum;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Webhooks\ProcessBrushCrazyWebhookJob;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Serial: the cutover, the correction cutoff and the source credentials are app settings, which
 * land in Redis and on the `ecosystem` connection and would leak into every parallel test.
 *
 * Every path here returns before the job reads the BrushCrazy database, so none needs one.
 */
#[Group('serial')]
final class ProcessBrushCrazyWebhookJobTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'event', 'ecosystem'];

    private const string SECRET = 'test';

    private const array APP_SETTINGS = [
        ConfigurationEnum::BRUSHCRAZY_CUTOVER_AT,
        ConfigurationEnum::BRUSHCRAZY_TZ_CORRECTION_CUTOFF,
        ConfigurationEnum::BRUSHCRAZY_DB_HOST,
        ConfigurationEnum::BRUSHCRAZY_DB_DATABASE,
    ];

    /** @var array<string, mixed> */
    private array $originalSettings = [];

    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);

        foreach (self::APP_SETTINGS as $key) {
            $this->originalSettings[$key->value] = $app->get($key->value);
            $app->del($key->value);
        }

        $this->company = Companies::factory()->create();
    }

    protected function tearDown(): void
    {
        $app = app(Apps::class);

        foreach ($this->originalSettings as $key => $value) {
            $value !== null ? $app->set($key, $value) : $app->del($key);
        }

        $this->company->del(ConfigurationEnum::BRUSHCRAZY_SYNC_ENABLED->value);

        parent::tearDown();
    }

    public function testACompanyWithSyncDisabledSkipsTheWholeBatch(): void
    {
        $results = $this->job([
            ['entity_type' => 'lesson', 'entity_id' => 1],
            ['entity_type' => 'user', 'entity_id' => 2],
        ])->execute();

        $this->assertSame(2, $results['skipped']);
        $this->assertSame(0, $results['synced']);
        $this->assertSame(0, $results['failed']);
        $this->assertStringContainsString('disabled', $results['reason']);
    }

    public function testAnAppPastCutoverSkipsTheWholeBatch(): void
    {
        $this->company->set(ConfigurationEnum::BRUSHCRAZY_SYNC_ENABLED->value, true);
        app(Apps::class)->set(
            ConfigurationEnum::BRUSHCRAZY_CUTOVER_AT->value,
            Carbon::now('UTC')->subDay()->toIso8601String()
        );

        $results = $this->job([['entity_type' => 'lesson', 'entity_id' => 1]])->execute();

        $this->assertSame(1, $results['skipped']);
        $this->assertSame(0, $results['failed']);
        $this->assertStringContainsString('BRUSHCRAZY_CUTOVER_AT', $results['reason']);
    }

    /**
     * Regression: (int) null made a company with no studio id read as studio 0, the Corporate
     * studio, so its pointers were synced into the wrong tenant's data.
     */
    public function testACompanyWithoutAStudioIdSkipsTheBatchInsteadOfActingAsStudioZero(): void
    {
        $this->company->set(ConfigurationEnum::BRUSHCRAZY_SYNC_ENABLED->value, true);
        Exceptions::fake();

        $results = $this->job([['entity_type' => 'lesson', 'entity_id' => 1]])->execute();

        $this->assertSame(1, $results['skipped']);
        $this->assertSame(0, $results['failed']);
        $this->assertStringContainsString('BRUSHCRAZY_STUDIO_ID', $results['reason']);
        Exceptions::assertNothingReported();
    }

    /**
     * Regression: `new Client($app)` threw a ValidationException on every delivery while the
     * connector was not set up, and each one reached Sentry.
     */
    public function testMissingDatabaseConfigurationSkipsTheBatchWithoutThrowing(): void
    {
        $this->company->set(ConfigurationEnum::BRUSHCRAZY_SYNC_ENABLED->value, true);
        $this->company->set(CustomFieldEnum::BRUSHCRAZY_STUDIO_ID->value, random_int(900_000, 999_999));

        $results = $this->job([['entity_type' => 'user', 'entity_id' => 1]])->execute();

        $this->assertSame(1, $results['skipped']);
        $this->assertSame(0, $results['failed']);
        $this->assertStringContainsString('database configuration', $results['reason']);
    }

    public function testAStudioWithoutAThemeAreaSkipsTheBatchWithoutThrowing(): void
    {
        $app = app(Apps::class);
        $this->company->set(ConfigurationEnum::BRUSHCRAZY_SYNC_ENABLED->value, true);
        $this->company->set(CustomFieldEnum::BRUSHCRAZY_STUDIO_ID->value, random_int(900_000, 999_999));
        $app->set(ConfigurationEnum::BRUSHCRAZY_DB_HOST->value, '127.0.0.1');
        $app->set(ConfigurationEnum::BRUSHCRAZY_DB_DATABASE->value, 'brushcrazy_unreachable');

        $results = $this->job([['entity_type' => 'lesson', 'entity_id' => 1]])->execute();

        $this->assertSame(1, $results['skipped']);
        $this->assertSame(0, $results['failed']);
        $this->assertStringContainsString('theme area', $results['reason']);
    }

    /**
     * Regression: CorrectionCutoff::pinned() threw inside the loop, so the first class pointer
     * aborted the batch half-way and reported the expected "not imported yet" state to Sentry.
     */
    public function testClassPointersAreSkippedWithAReasonWhileNoCutoffIsPinned(): void
    {
        $app = app(Apps::class);
        $bcStudioId = random_int(900_000, 999_999);

        $this->company->set(ConfigurationEnum::BRUSHCRAZY_SYNC_ENABLED->value, true);
        $this->company->set(CustomFieldEnum::BRUSHCRAZY_STUDIO_ID->value, $bcStudioId);
        $app->set(ConfigurationEnum::BRUSHCRAZY_DB_HOST->value, '127.0.0.1');
        $app->set(ConfigurationEnum::BRUSHCRAZY_DB_DATABASE->value, 'brushcrazy_unreachable');

        $area = ThemeArea::firstOrCreate(
            [
                'name' => 'Studio ' . $bcStudioId,
                'apps_id' => $app->getId(),
                'companies_id' => $this->company->getId(),
            ],
            ['users_id' => auth()->user()->getId()],
        );
        $area->set(CustomFieldEnum::BRUSHCRAZY_STUDIO_ID->value, $bcStudioId);

        $results = $this->job([
            ['entity_type' => 'lesson', 'entity_id' => 1],
            ['entity_type' => 'workshop', 'entity_id' => 2],
        ])->execute();

        $this->assertSame(2, $results['skipped']);
        $this->assertSame(0, $results['failed']);
        $this->assertCount(1, $results['warnings']);
        $this->assertStringContainsString('cutoff is not pinned', $results['warnings'][0]);
    }

    public function testASignedFreshRequestIsAuthentic(): void
    {
        $timestamp = (string) time();
        $body = '{"events":[]}';

        $this->assertTrue($this->authenticate(
            $body,
            $timestamp,
            ProcessBrushCrazyWebhookJob::signature($timestamp, $body, self::SECRET)
        ));
    }

    public function testAStaleTimestampIsRejectedEvenWhenTheSignatureMatches(): void
    {
        $timestamp = (string) (time() - 301);
        $body = '{"events":[]}';

        $this->assertFalse($this->authenticate(
            $body,
            $timestamp,
            ProcessBrushCrazyWebhookJob::signature($timestamp, $body, self::SECRET)
        ));
    }

    public function testAMissingTimestampIsRejected(): void
    {
        $body = '{"events":[]}';

        $this->assertFalse($this->authenticate(
            $body,
            null,
            hash_hmac('sha256', $body, self::SECRET)
        ));
    }

    public function testATamperedBodyIsRejected(): void
    {
        $timestamp = (string) time();

        $this->assertFalse($this->authenticate(
            '{"events":[{"entity_type":"user","entity_id":1}]}',
            $timestamp,
            ProcessBrushCrazyWebhookJob::signature($timestamp, '{"events":[]}', self::SECRET)
        ));
    }

    private function authenticate(string $body, ?string $timestamp, string $signature): bool
    {
        $server = ['HTTP_X_BRUSHCRAZY_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'];

        if ($timestamp !== null) {
            $server['HTTP_X_BRUSHCRAZY_TIMESTAMP'] = $timestamp;
        }

        $receiver = new ReceiverWebhook();
        $receiver->configuration = ['signing_secret' => self::SECRET];

        return ProcessBrushCrazyWebhookJob::authenticateRequest(
            Request::create(
                'https://example.com/receiver/brushcrazy',
                'POST',
                server: $server,
                content: $body
            ),
            $receiver
        );
    }

    /**
     * @param  array<int, array{entity_type: string, entity_id: int}>  $events
     */
    private function job(array $events): ProcessBrushCrazyWebhookJob
    {
        $receiver = ReceiverWebhook::factory()->create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => auth()->user()->getId(),
            'configuration' => ['signing_secret' => self::SECRET],
        ]);

        $call = new ReceiverWebhookCall();
        $call->receiver_webhooks_id = $receiver->getId();
        $call->url = 'https://example.com/receiver/brushcrazy';
        $call->payload = ['events' => $events];
        $call->saveOrFail();

        return new ProcessBrushCrazyWebhookJob($call);
    }
}
