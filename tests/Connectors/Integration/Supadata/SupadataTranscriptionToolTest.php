<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Supadata;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Supadata\Enums\ConfigurationEnum;
use Kanvas\Connectors\Supadata\Handlers\SupadataHandler;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Neuron\Tools\Supadata\GetTranscriptionTool;
use Kanvas\Intelligence\Agents\Services\AgentToolDiscoveryService;
use Kanvas\Regions\Models\Regions;
use Tests\TestCase;

class SupadataTranscriptionToolTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * The API key lives on the company's settings store, which persists outside the ambient test
     * transaction — save and restore it so these tests never clobber a real configured key.
     */
    private mixed $originalApiKey = null;

    protected function setUp(): void
    {
        parent::setUp();

        $company = static::$cachedUser->getCurrentCompany();
        $this->originalApiKey = $company->get(ConfigurationEnum::SUPADATA_API_KEY->value);
        $company->set(ConfigurationEnum::SUPADATA_API_KEY->value, 'sd-test-key');

        Sleep::fake();
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        static::$cachedUser->getCurrentCompany()
            ->set(ConfigurationEnum::SUPADATA_API_KEY->value, $this->originalApiKey);

        parent::tearDown();
    }

    public function test_it_sends_the_api_key_header_and_returns_the_transcript(): void
    {
        [$app, $company] = $this->context();

        Http::fake([
            '*api.supadata.ai/v1/transcript*' => Http::response([
                'content' => 'We ship on Friday.',
                'lang' => 'en',
                'availableLangs' => ['en', 'es'],
            ], 200),
        ]);

        $result = $this->tool($app, $company)->__invoke(url: 'https://youtu.be/dQw4w9WgXcQ');

        $this->assertSame('success', $result['status']);
        $this->assertSame('We ship on Friday.', $result['transcript']);
        $this->assertSame('en', $result['lang']);
        $this->assertSame(['en', 'es'], $result['available_langs']);

        Http::assertSent(fn (Request $request) => $request->hasHeader('x-api-key', 'sd-test-key'));
    }

    /**
     * http_build_query renders true as `1`, which Supadata does not read as a boolean — the transcript
     * comes back as timestamped chunks and the plain-text request is silently ignored.
     */
    public function test_it_sends_booleans_as_the_words_true_and_false(): void
    {
        [$app, $company] = $this->context();

        Http::fake(['*api.supadata.ai/v1/transcript*' => Http::response(['content' => 'hi', 'lang' => 'en'], 200)]);

        $this->tool($app, $company)->__invoke(url: 'https://youtu.be/x', mode: 'native', lang: 'es');

        Http::assertSent(function (Request $request): bool {
            $query = $request->data();

            return $query['text'] === 'true'
                && $query['mode'] === 'native'
                && $query['lang'] === 'es';
        });
    }

    public function test_timestamps_are_requested_as_chunks_and_rendered_as_readable_offsets(): void
    {
        [$app, $company] = $this->context();

        Http::fake([
            '*api.supadata.ai/v1/transcript*' => Http::response([
                'content' => [
                    ['text' => 'Kickoff.', 'offset' => 0, 'duration' => 1000, 'lang' => 'en'],
                    ['text' => 'Blocked on the API.', 'offset' => 125000, 'duration' => 2000, 'lang' => 'en'],
                    ['text' => 'Wrapping up.', 'offset' => 3725000, 'duration' => 2000, 'lang' => 'en'],
                ],
                'lang' => 'en',
            ], 200),
        ]);

        $result = $this->tool($app, $company)->__invoke(
            url: 'https://youtu.be/x',
            include_timestamps: true,
        );

        $this->assertSame(
            "[0:00] Kickoff.\n[2:05] Blocked on the API.\n[1:02:05] Wrapping up.",
            $result['transcript'],
        );

        Http::assertSent(fn (Request $request) => $request->data()['text'] === 'false');
    }

    public function test_a_long_recording_polls_its_job_until_the_transcript_is_ready(): void
    {
        [$app, $company] = $this->context();

        Http::fake([
            '*api.supadata.ai/v1/transcript/*' => Http::sequence()
                ->push(['status' => 'active'], 200)
                ->push(['status' => 'completed', 'content' => 'The whole call.', 'lang' => 'en'], 200),
            '*api.supadata.ai/v1/transcript*' => Http::response(['jobId' => 'job-1'], 202),
        ]);

        $result = $this->tool($app, $company)->__invoke(url: 'https://example.com/call.mp4');

        $this->assertSame('success', $result['status']);
        $this->assertSame('The whole call.', $result['transcript']);
        Sleep::assertSleptTimes(2);
    }

    /**
     * Waiting out a two-hour recording would burn the turn, so the budget stops early and hands the
     * job id back — a transcript the model can collect is worth more than a timeout.
     */
    public function test_a_job_that_outlasts_the_wait_budget_comes_back_as_a_resumable_job_id(): void
    {
        [$app, $company] = $this->context();

        Http::fake([
            '*api.supadata.ai/v1/transcript/*' => Http::response(['status' => 'queued'], 200),
            '*api.supadata.ai/v1/transcript*' => Http::response(['jobId' => 'job-2'], 202),
        ]);

        $result = $this->tool($app, $company)->__invoke(url: 'https://example.com/long.mp4');

        $this->assertSame('processing', $result['status']);
        $this->assertSame('job-2', $result['job_id']);
        Sleep::assertSleptTimes(5);
    }

    public function test_a_job_id_alone_collects_the_transcript_without_starting_a_new_one(): void
    {
        [$app, $company] = $this->context();

        Http::fake([
            '*api.supadata.ai/v1/transcript/job-2*' => Http::response([
                'status' => 'completed',
                'content' => 'Collected later.',
                'lang' => 'en',
            ], 200),
        ]);

        $result = $this->tool($app, $company)->__invoke(job_id: 'job-2');

        $this->assertSame('Collected later.', $result['transcript']);
        Http::assertSentCount(1);
    }

    public function test_a_failed_job_is_reported_as_an_error_rather_than_an_empty_transcript(): void
    {
        [$app, $company] = $this->context();

        Http::fake([
            '*api.supadata.ai/v1/transcript/*' => Http::response([
                'status' => 'failed',
                'error' => 'The media could not be downloaded.',
            ], 200),
        ]);

        $result = $this->tool($app, $company)->__invoke(job_id: 'job-3');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('could not be downloaded', $result['error']);
    }

    /**
     * Supadata answers "no transcript available" with HTTP 206 and an error envelope. 206 is a success
     * status, so a client that only checks `failed()` hands the agent an empty transcript instead.
     */
    public function test_a_206_transcript_unavailable_is_surfaced_as_an_error(): void
    {
        [$app, $company] = $this->context();

        Http::fake([
            '*api.supadata.ai/v1/transcript*' => Http::response([
                'error' => 'transcript-unavailable',
                'message' => 'No transcript is available for this video',
            ], 206),
        ]);

        $result = $this->tool($app, $company)->__invoke(url: 'https://youtu.be/x');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('No transcript is available', $result['error']);
    }

    /**
     * A clip with no captions is an ordinary answer, not an incident — reporting it would flood Sentry
     * with something nobody can act on. `mode=native` is the case that has a remedy, so it is the only
     * one the model is told to change and try again.
     */
    public function test_an_unavailable_transcript_in_native_mode_suggests_generating_one(): void
    {
        [$app, $company] = $this->context();

        Http::fake([
            '*api.supadata.ai/v1/transcript*' => Http::response([
                'error' => 'transcript-unavailable',
                'message' => 'No transcript is available for this video',
            ], 206),
        ]);

        $native = $this->tool($app, $company)->__invoke(url: 'https://youtu.be/x', mode: 'native');
        $auto = $this->tool($app, $company)->__invoke(url: 'https://youtu.be/x');

        $this->assertStringContainsString('without mode "native"', $native['error']);
        $this->assertStringNotContainsString('without mode', $auto['error']);
    }

    public function test_an_empty_transcript_tells_the_agent_no_speech_was_found(): void
    {
        [$app, $company] = $this->context();

        Http::fake([
            '*api.supadata.ai/v1/transcript*' => Http::response(['content' => '', 'lang' => 'en'], 200),
        ]);

        $result = $this->tool($app, $company)->__invoke(url: 'https://youtu.be/x');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('no speech', $result['error']);
    }

    public function test_a_transcript_that_would_swamp_the_context_window_is_truncated(): void
    {
        [$app, $company] = $this->context();

        Http::fake([
            '*api.supadata.ai/v1/transcript*' => Http::response([
                'content' => str_repeat('x', 60000),
                'lang' => 'en',
            ], 200),
        ]);

        $result = $this->tool($app, $company)->__invoke(url: 'https://youtu.be/x');

        $this->assertLessThan(60000, mb_strlen($result['transcript']));
        $this->assertStringContainsString('truncated', $result['transcript']);
    }

    public function test_it_rejects_a_malformed_url_before_spending_a_credit(): void
    {
        [$app, $company] = $this->context();

        Http::fake();

        $result = $this->tool($app, $company)->__invoke(url: 'not-a-url');

        $this->assertStringContainsString('valid http(s) URL', $result['error']);
        Http::assertNothingSent();
    }

    public function test_it_asks_for_a_url_when_given_neither_a_url_nor_a_job_id(): void
    {
        [$app, $company] = $this->context();

        Http::fake();

        $result = $this->tool($app, $company)->__invoke();

        $this->assertStringContainsString('url', $result['error']);
        Http::assertNothingSent();
    }

    public function test_it_reports_a_setup_error_when_neither_the_company_nor_the_app_has_a_key(): void
    {
        [$app, $company] = $this->context();
        $company->set(ConfigurationEnum::SUPADATA_API_KEY->value, '');

        Http::fake();

        $this->withAppKey($app, '', function () use ($app, $company): void {
            $result = $this->tool($app, $company)->__invoke(url: 'https://youtu.be/x');

            $this->assertStringContainsString('not configured', $result['error']);
            Http::assertNothingSent();
        });
    }

    /**
     * A company that has not connected its own Supadata account still transcribes, on the platform's
     * key — the same company-then-app fallback RespondIO uses.
     */
    public function test_a_company_without_its_own_key_falls_back_to_the_apps(): void
    {
        [$app, $company] = $this->context();
        $company->set(ConfigurationEnum::SUPADATA_API_KEY->value, '');

        Http::fake(['*api.supadata.ai/v1/transcript*' => Http::response(['content' => 'hi', 'lang' => 'en'], 200)]);

        $this->withAppKey($app, 'sd-app-wide-key', function () use ($app, $company): void {
            $result = $this->tool($app, $company)->__invoke(url: 'https://youtu.be/x');

            $this->assertSame('success', $result['status']);
            Http::assertSent(fn (Request $request) => $request->hasHeader('x-api-key', 'sd-app-wide-key'));
        });
    }

    /**
     * A tenant that brings its own account has to spend its own credits — an app key that quietly won
     * would bill the platform for work the company is paying to do itself.
     */
    public function test_the_companys_own_key_wins_over_the_apps(): void
    {
        [$app, $company] = $this->context();

        Http::fake(['*api.supadata.ai/v1/transcript*' => Http::response(['content' => 'hi', 'lang' => 'en'], 200)]);

        $this->withAppKey($app, 'sd-app-wide-key', function () use ($app, $company): void {
            $this->tool($app, $company)->__invoke(url: 'https://youtu.be/x');

            Http::assertSent(fn (Request $request) => $request->hasHeader('x-api-key', 'sd-test-key'));
        });
    }

    /**
     * The catalog sync is what puts a tool in the UI picker and in reach of the PM agent's
     * grant/hire tools, so a tool missing from discovery is a tool nobody can be given.
     */
    public function test_the_transcription_tool_is_discoverable_for_the_neuron_runtime(): void
    {
        $entry = collect(new AgentToolDiscoveryService()->discover())
            ->keyBy('class')
            ->get(GetTranscriptionTool::class);

        $this->assertNotNull($entry, 'GetTranscriptionTool is not in the tool catalog.');
        $this->assertSame('knowledge', $entry['category']);
        $this->assertContains('neuron', $entry['frameworks']);
        $this->assertContains('claude', $entry['frameworks']);

        // The whole reason the #[AgentTool] attribute carries its own description: the catalog is read
        // by whoever GRANTS the tool, and transcription is metered. Drop the attribute description and
        // the runtime one silently takes over — it never mentions the cost.
        $this->assertStringContainsStringIgnoringCase('bills per minute', (string) $entry['description']);
    }

    public function test_handler_stores_the_key_on_the_company_once_supadata_accepts_it(): void
    {
        [$app, $company] = $this->context();
        $company->set(ConfigurationEnum::SUPADATA_API_KEY->value, '');

        Http::fake(['*api.supadata.ai/v1/me' => Http::response(['organizationId' => 'org-1'], 200)]);

        $this->assertTrue($this->handler($app, $company, ['api_key' => ' sd-fresh-key '])->setup());
        $this->assertSame('sd-fresh-key', $company->get(ConfigurationEnum::SUPADATA_API_KEY->value));
        $this->assertNotSame('sd-fresh-key', $app->get(ConfigurationEnum::SUPADATA_API_KEY->value));

        Http::assertSent(fn (Request $request) => $request->hasHeader('x-api-key', 'sd-fresh-key'));
    }

    public function test_handler_refuses_a_key_supadata_rejects_and_leaves_the_stored_one_alone(): void
    {
        [$app, $company] = $this->context();

        Http::fake(['*api.supadata.ai/v1/me' => Http::response(['error' => 'unauthorized'], 401)]);

        try {
            $this->handler($app, $company, ['api_key' => 'sd-bad'])->setup();
            $this->fail('A rejected key should not set up the integration.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Invalid Supadata API key', $e->getMessage());
        }

        $this->assertSame('sd-test-key', $company->get(ConfigurationEnum::SUPADATA_API_KEY->value));
    }

    public function test_handler_rejects_an_empty_key_without_calling_supadata(): void
    {
        [$app, $company] = $this->context();

        Http::fake();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Supadata API key is required.');

        try {
            $this->handler($app, $company, ['api_key' => '   '])->setup();
        } finally {
            Http::assertNothingSent();
        }
    }

    /**
     * The integrations row is what makes the handler reachable — the shared integrationCompany
     * mutation resolves `handler` from it, so without the row the setup path does not exist.
     */
    public function test_supadata_is_registered_as_an_integration(): void
    {
        $integration = DB::connection('workflow')
            ->table('integrations')
            ->where('name', 'supadata')
            ->where('apps_id', 0)
            ->first();

        $this->assertNotNull($integration, 'The supadata integrations row is missing — run the migration.');
        $this->assertSame(SupadataHandler::class, $integration->handler);
        $this->assertArrayHasKey('api_key', json_decode((string) $integration->config, true));
    }

    /**
     * The app settings store persists outside the ambient test transaction, so every test that leans
     * on the app-level fallback has to put the real key back whatever happens.
     */
    private function withAppKey(Apps $app, string $key, callable $assertions): void
    {
        $original = $app->get(ConfigurationEnum::SUPADATA_API_KEY->value);
        $app->set(ConfigurationEnum::SUPADATA_API_KEY->value, $key);

        try {
            $assertions();
        } finally {
            $app->set(ConfigurationEnum::SUPADATA_API_KEY->value, $original);
        }
    }

    private function tool(Apps $app, Companies $company): GetTranscriptionTool
    {
        return new GetTranscriptionTool()->withContext($app, $company, static::$cachedUser);
    }

    private function handler(Apps $app, Companies $company, array $data): SupadataHandler
    {
        return new SupadataHandler(
            $app,
            $company,
            Regions::getDefault($company, $app),
            $data,
        );
    }

    /**
     * @return array{0: Apps, 1: Companies}
     */
    private function context(): array
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        return [$app, $company];
    }
}
