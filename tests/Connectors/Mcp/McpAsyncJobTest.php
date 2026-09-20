<?php

declare(strict_types=1);

namespace Tests\Connectors\Mcp;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Actions\FollowMcpAsyncJobAction;
use Kanvas\Connectors\Mcp\DataTransferObject\McpServerConfig;
use Kanvas\Connectors\Mcp\Jobs\PollMcpAsyncJob;
use Kanvas\Connectors\Mcp\Jobs\ResumeAgentFromMcpAsyncJob;
use Kanvas\Connectors\Mcp\Services\McpConnectionService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Mcp\CachedMcpConnector;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Capability\Enums\McpAsyncJobStatusEnum;
use Kanvas\NervousSystem\Capability\Models\McpAsyncJob;
use Kanvas\Workflow\Models\Integrations;
use Tests\Stubs\Connectors\Mcp\FakeMcpServer;

/**
 * A tool that starts a job and returns before it finishes (Browser Use's `run_session` ran ~10 minutes)
 * used to leave the model polling the status tool until Neuron's per-turn run cap killed the turn. The
 * turn now hands off, a background poll follows the job, and the agent is woken with the result.
 */
final class McpAsyncJobTest extends McpTestCase
{
    private const string LIVE_URL = 'https://live.example.test/session/s-1';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function testTheJobBlockIsReadFromTheServerRowAndAnIncompleteOneIsIgnored(): void
    {
        $config = McpServerConfig::fromIntegration($this->makeIntegration([
            'async_jobs' => [
                'createJiraIssue' => $this->jobBlock(),
                'searchJiraIssuesUsingJql' => ['status_tool' => 'get_session'],
            ],
        ]));

        $this->assertSame(['createJiraIssue'], array_keys($config->asyncJobs));
        $this->assertSame('searchJiraIssuesUsingJql', $config->asyncJob('createJiraIssue')?->statusTool);
        $this->assertNull($config->asyncJob('searchJiraIssuesUsingJql'), 'A job the poll cannot follow must not be handed off.');
    }

    public function testStartingAJobHandsOffAndTellsTheModelToStopPolling(): void
    {
        [$agent, $integration] = $this->asyncServer();
        $session = $this->makeSession($agent);

        $answer = $this->invokeStart($agent, $integration, $this->sessionPayload('running', self::LIVE_URL), $session);

        $this->assertIsString($answer);
        $decoded = json_decode($answer, true);
        $this->assertSame('running_in_background', $decoded['status']);
        $this->assertSame('s-1', $decoded['job_id']);
        $this->assertSame(self::LIVE_URL, $decoded['live_url']);
        $this->assertStringContainsString('Do not call the status tool', $decoded['message']);

        $job = $this->onlyJobFor($agent);
        $this->assertSame(McpAsyncJobStatusEnum::RUNNING->value, $job->status);
        $this->assertSame($session->uuid, $job->session_uuid);
        $this->assertSame($this->mcpUser->getId(), $job->users_id);
        $this->assertSame(self::LIVE_URL, $job->live_url);
        $this->assertNotNull($job->expires_at);

        Queue::assertPushed(PollMcpAsyncJob::class, fn (PollMcpAsyncJob $poll): bool => $poll->asyncJob->is($job));
    }

    public function testAJobThatAlreadyFinishedIsAnsweredWithTheVendorResult(): void
    {
        [$agent, $integration] = $this->asyncServer();

        $answer = $this->invokeStart($agent, $integration, $this->sessionPayload('idle'), $this->makeSession($agent));

        $this->assertIsArray($answer);
        $this->assertSame(0, McpAsyncJob::query()->where('agents_id', $agent->getId())->count());
        Queue::assertNotPushed(PollMcpAsyncJob::class);
    }

    public function testWithoutAConversationThereIsNowhereToResumeSoNothingIsHandedOff(): void
    {
        [$agent, $integration] = $this->asyncServer();

        $answer = $this->invokeStart($agent, $integration, $this->sessionPayload('running'), session: null);

        $this->assertIsArray($answer);
        $this->assertSame(0, McpAsyncJob::query()->where('agents_id', $agent->getId())->count());
    }

    public function testAToolNotDeclaredAsAJobIsUntouched(): void
    {
        [$agent, $integration] = $this->asyncServer();
        $connector = $this->connectorFor($agent, $integration, $this->sessionPayload('running'), $this->makeSession($agent));

        $answer = $connector->invokeTool(['name' => 'fake__searchJiraIssuesUsingJql'], ['jql' => 'x']);

        $this->assertIsArray($answer);
        $this->assertSame(0, McpAsyncJob::query()->where('agents_id', $agent->getId())->count());
    }

    public function testTheConversationSurvivesTheConnectorBeingSerialized(): void
    {
        [$agent, $integration] = $this->asyncServer();
        $session = $this->makeSession($agent);
        $connector = unserialize(serialize(
            $this->connectorFor($agent, $integration, $this->sessionPayload('running'), $session)
        ));

        $connector->toolsFromDescriptors(FakeMcpServer::twoTools(), 'fake');
        $connector->invokeTool(['name' => 'fake__createJiraIssue'], []);

        // An interrupted workflow carries MCP tools across a serialize — the job must still know its way home.
        $this->assertSame($session->uuid, $this->onlyJobFor($agent)->session_uuid);
    }

    public function testARunningJobIsCheckedAndLeftRunning(): void
    {
        [$agent, $integration] = $this->asyncServer();
        $job = $this->runningJob($agent, $integration);

        $job = new FollowMcpAsyncJobAction($job, FakeMcpServer::handshakeThenCalls($this->sessionPayload('running', self::LIVE_URL)))->execute();

        $this->assertTrue($job->isRunning());
        $this->assertSame(1, $job->poll_count);
        $this->assertSame('running', $job->external_status);
        $this->assertSame(self::LIVE_URL, $job->live_url);
        Queue::assertNotPushed(ResumeAgentFromMcpAsyncJob::class);
    }

    public function testTheLiveUrlIsKeptAfterTheVendorClearsIt(): void
    {
        [$agent, $integration] = $this->asyncServer();
        $job = $this->runningJob($agent, $integration);
        $job->live_url = self::LIVE_URL;
        $job->saveOrFail();

        // Browser Use reports `live_url: null` once the browser stops — the link the person was given stays.
        $job = new FollowMcpAsyncJobAction($job, FakeMcpServer::handshakeThenCalls($this->sessionPayload('running')))->execute();

        $this->assertSame(self::LIVE_URL, $job->live_url);
    }

    public function testALiveUrlWithNowhereToPostIsMarkedSeenRatherThanRetriedEveryCheck(): void
    {
        [$agent, $integration] = $this->asyncServer();
        $job = $this->runningJob($agent, $integration);

        $job = new FollowMcpAsyncJobAction($job, FakeMcpServer::handshakeThenCalls($this->sessionPayload('running', self::LIVE_URL)))->execute();

        $this->assertSame(self::LIVE_URL, $job->live_url_posted);
        $this->assertFalse($job->hasUnpostedLiveUrl());
    }

    public function testAJobThatIsAlreadyOverIsNotAnnouncedAsWatchLiveFirst(): void
    {
        [$agent, $integration] = $this->asyncServer();
        $job = $this->runningJob($agent, $integration);

        $job = new FollowMcpAsyncJobAction(
            $job,
            FakeMcpServer::handshakeThenCalls($this->sessionPayload('idle', self::LIVE_URL))
        )->execute();

        // A short task is over on the first check — "open it to watch" on a dead session is worse than
        // no link, and it would land right before the result.
        $this->assertNull($job->live_url_posted);
        $this->assertSame(McpAsyncJobStatusEnum::COMPLETED->value, $job->status);
    }

    public function testACutResultSaysSoInsteadOfTrailingOff(): void
    {
        [$agent, $integration] = $this->asyncServer();
        $job = $this->runningJob($agent, $integration);

        $job->finish(McpAsyncJobStatusEnum::COMPLETED, result: str_repeat('row,', McpAsyncJob::MAX_RESULT_CHARS));

        // Str::limit's default "..." reads as the vendor's own ellipsis, and the agent reports a partial
        // table as the day's numbers.
        $this->assertStringContainsString('truncated by Kanvas', (string) $job->result);
        $this->assertStringContainsString('truncated by Kanvas', $job->resumeInstruction());
    }

    public function testAFinishedJobStoresTheResultAndResumesTheAgent(): void
    {
        [$agent, $integration] = $this->asyncServer();
        $job = $this->runningJob($agent, $integration);

        $job = new FollowMcpAsyncJobAction($job, FakeMcpServer::handshakeThenCalls($this->sessionPayload('idle')))->execute();

        $this->assertSame(McpAsyncJobStatusEnum::COMPLETED->value, $job->status);
        $this->assertNotNull($job->completed_at);
        $this->assertStringContainsString('10 orders shipped', (string) $job->result);
        Queue::assertPushed(ResumeAgentFromMcpAsyncJob::class, fn (ResumeAgentFromMcpAsyncJob $resume): bool => $resume->asyncJob->is($job));
    }

    public function testAJobPastItsDeadlineTimesOutAndResumesTheAgent(): void
    {
        [$agent, $integration] = $this->asyncServer();
        $job = $this->runningJob($agent, $integration);
        $job->expires_at = Carbon::now()->subMinute();
        $job->saveOrFail();

        $job = new FollowMcpAsyncJobAction($job, FakeMcpServer::handshakeThenCalls($this->sessionPayload('running')))->execute();

        $this->assertSame(McpAsyncJobStatusEnum::TIMED_OUT->value, $job->status);
        Queue::assertPushed(ResumeAgentFromMcpAsyncJob::class);
    }

    public function testAFailedCheckIsRetriedUntilTooManyInARow(): void
    {
        [$agent, $integration] = $this->asyncServer();
        $job = $this->runningJob($agent, $integration);
        $garbage = ['content' => [['type' => 'text', 'text' => 'upstream hiccup']]];

        $job = new FollowMcpAsyncJobAction($job, FakeMcpServer::handshakeThenCalls($garbage))->execute();

        $this->assertTrue($job->isRunning(), 'One bad check is a blip, not a failed job.');
        $this->assertSame(1, $job->consecutive_errors);

        $job->consecutive_errors = FollowMcpAsyncJobAction::MAX_CONSECUTIVE_ERRORS - 1;
        $job->saveOrFail();

        $job = new FollowMcpAsyncJobAction($job, FakeMcpServer::handshakeThenCalls($garbage))->execute();

        $this->assertSame(McpAsyncJobStatusEnum::FAILED->value, $job->status);
        $this->assertStringContainsString('did not answer with a JSON status', (string) $job->last_error);
        Queue::assertPushed(ResumeAgentFromMcpAsyncJob::class);
    }

    public function testAJobThatAlreadyEndedIsNotCheckedAgain(): void
    {
        [$agent, $integration] = $this->asyncServer();
        $job = $this->runningJob($agent, $integration);
        $job->finish(McpAsyncJobStatusEnum::COMPLETED, result: '{}');

        // An empty transport queue fails the test on any call — a duplicate poll must not reach the vendor.
        new FollowMcpAsyncJobAction($job, FakeMcpServer::handshakeThenCalls([], 0))->execute();

        Queue::assertNotPushed(ResumeAgentFromMcpAsyncJob::class);
    }

    public function testTheResumeInstructionCarriesTheResultAndForbidsARerun(): void
    {
        [$agent, $integration] = $this->asyncServer();
        $job = $this->runningJob($agent, $integration);
        $job->external_status = 'idle';
        $job->finish(McpAsyncJobStatusEnum::COMPLETED, result: '{"output": "10 orders shipped"}');

        $instruction = $job->resumeInstruction();

        $this->assertStringContainsString('10 orders shipped', $instruction);
        $this->assertStringContainsString('Do not start the job again', $instruction);

        $failed = $this->runningJob($agent, $integration);
        $failed->finish(McpAsyncJobStatusEnum::FAILED, error: 'vendor down');

        $this->assertStringContainsString('vendor down', $failed->resumeInstruction());
    }

    public function testTheSessionResolvesThroughTheAgentThatStartedTheJob(): void
    {
        [$agent, $integration] = $this->asyncServer();
        $session = $this->makeSession($agent);
        $job = $this->runningJob($agent, $integration, $session->uuid);

        // A channel session uuid is shared by every agent on the channel.
        $this->makeSession($this->makeAgent(), $session->uuid);

        $this->assertSame($session->getId(), $job->resolveSession()?->getId());
    }

    /**
     * @return array{0: Agent, 1: Integrations}
     */
    private function asyncServer(): array
    {
        $integration = $this->makeIntegration([
            'async_jobs' => ['createJiraIssue' => $this->jobBlock()],
        ]);

        return [$this->makeAgent(), $integration];
    }

    /**
     * @return array<string, mixed>
     */
    private function jobBlock(): array
    {
        return [
            'status_tool' => 'searchJiraIssuesUsingJql',
            'id_field' => 'session_id',
            'status_field' => 'status',
            'done_statuses' => ['idle', 'stopped', 'timed_out', 'error'],
            'live_url_field' => 'live_url',
        ];
    }

    /**
     * Browser Use's real `get_session` shape: the payload is a JSON object inside a text item.
     *
     * @return array<string, mixed>
     */
    private function sessionPayload(string $status, ?string $liveUrl = null): array
    {
        return [
            'content' => [[
                'type' => 'text',
                'text' => (string) json_encode([
                    'session_id' => 's-1',
                    'status' => $status,
                    'output' => $status === 'idle' ? '10 orders shipped' : null,
                    'live_url' => $liveUrl,
                ]),
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $callResult
     */
    private function connectorFor(
        Agent $agent,
        Integrations $integration,
        array $callResult,
        ?Session $session
    ): CachedMcpConnector {
        $connector = new McpConnectionService($agent, $integration, FakeMcpServer::handshakeThenCalls($callResult))
            ->connector()
            ->withLedgerContext(null, $agent->getId())
            ->withConversation($session?->uuid, $this->mcpUser->getId());

        $connector->toolsFromDescriptors(FakeMcpServer::twoTools(), 'fake');

        return $connector;
    }

    /**
     * @param array<string, mixed> $callResult
     */
    private function invokeStart(
        Agent $agent,
        Integrations $integration,
        array $callResult,
        ?Session $session
    ): mixed {
        return $this->connectorFor($agent, $integration, $callResult, $session)
            ->invokeTool(['name' => 'fake__createJiraIssue'], []);
    }

    private function runningJob(Agent $agent, Integrations $integration, ?string $sessionUuid = null): McpAsyncJob
    {
        return $this->makeAsyncJob(
            agent: $agent,
            integration: $integration,
            startTool: 'createJiraIssue',
            externalId: 's-1',
            sessionUuid: $sessionUuid,
        );
    }

    private function onlyJobFor(Agent $agent): McpAsyncJob
    {
        $jobs = McpAsyncJob::query()->where('agents_id', $agent->getId())->get();
        $this->assertCount(1, $jobs);

        return $jobs->first();
    }

    private function makeSession(Agent $agent, ?string $uuid = null): Session
    {
        return Session::create([
            'apps_id' => $agent->apps_id,
            'companies_id' => $agent->companies_id,
            'agents_id' => $agent->getId(),
            'uuid' => $uuid ?? Str::uuid()->toString(),
            'canal_id' => '',
            'entity_namespace' => '',
            'entity_id' => 0,
            'user' => [],
            'content' => [],
        ]);
    }
}
