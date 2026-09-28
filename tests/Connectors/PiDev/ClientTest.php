<?php

declare(strict_types=1);

namespace Tests\Connectors\PiDev;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\PiDev\Client;
use Kanvas\Exceptions\ValidationException;
use Tests\Connectors\Traits\HasPiDevConfiguration;
use Tests\TestCase;

final class ClientTest extends TestCase
{
    use HasPiDevConfiguration;

    private Apps $currentApp;
    private Companies $currentCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->currentCompany = static::$cachedUser->getCurrentCompany();
    }

    public function testQueueWorkReturnsAcceptedJob(): void
    {
        $client = $this->piDevClientReturning($this->currentApp, $this->currentCompany, [
            $this->piDevJsonResponse(202, [
                'jobId' => 'job-123',
                'agentId' => 'agent-1',
                'status' => 'queued',
                'repoName' => 'widgets',
            ]),
        ]);

        $response = $client->queueWork([
            'agentId' => 'agent-1',
            'githubToken' => 'ghp_x',
            'workingGithubRepoUrl' => 'https://github.com/acme/widgets.git',
            'task' => 'Add a changelog',
        ]);

        $this->assertSame('job-123', $response['jobId']);
        $this->assertSame('queued', $response['status']);
    }

    public function testGetJobReturnsCompletedWithPullRequest(): void
    {
        $client = $this->piDevClientReturning($this->currentApp, $this->currentCompany, [
            $this->piDevJsonResponse(200, [
                'jobId' => 'job-123',
                'status' => 'completed',
                'result' => 'Opened PR #42.',
                'pullRequestUrl' => 'https://github.com/acme/widgets/pull/42',
            ]),
        ]);

        $response = $client->getJob('job-123');

        $this->assertSame('completed', $response['status']);
        $this->assertSame('https://github.com/acme/widgets/pull/42', $response['pullRequestUrl']);
    }

    public function testCancelJobReturnsCancelling(): void
    {
        $client = $this->piDevClientReturning($this->currentApp, $this->currentCompany, [
            $this->piDevJsonResponse(202, ['jobId' => 'job-123', 'status' => 'cancelling']),
        ]);

        $response = $client->cancelJob('job-123');

        $this->assertSame('cancelling', $response['status']);
    }

    public function testClientErrorSurfacesTheServerErrorMessage(): void
    {
        $client = $this->piDevClientReturning($this->currentApp, $this->currentCompany, [
            $this->piDevJsonResponse(400, [
                'error' => 'invalid request body',
                'details' => ['task is required'],
            ]),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('invalid request body');

        $client->getJob('job-123');
    }

    public function testFetchJobEventsParsesSseFrames(): void
    {
        $sse = "id: 1\nevent: status\ndata: \"queued\"\n\n"
            . "id: 2\nevent: text\ndata: \"I read the changelog format.\"\n\n"
            . "id: 3\nevent: pull_request\ndata: \"https://github.com/acme/widgets/pull/42\"\n\n"
            . "id: 4\nevent: done\ndata: \"completed\"\n\n";

        $client = $this->piDevClientReturning($this->currentApp, $this->currentCompany, [
            new Response(200, ['Content-Type' => 'text/event-stream'], $sse),
        ]);

        $frames = $client->fetchJobEvents('job-1');

        $this->assertCount(4, $frames);
        $this->assertSame('text', $frames[1]['event']);
        $this->assertSame('I read the changelog format.', $frames[1]['data']);
        $this->assertSame('pull_request', $frames[2]['event']);
        $this->assertSame('https://github.com/acme/widgets/pull/42', $frames[2]['data']);
    }

    public function testFetchJobEventsHonorsAfterId(): void
    {
        $sse = "id: 1\nevent: status\ndata: \"queued\"\n\n"
            . "id: 2\nevent: text\ndata: \"first\"\n\n"
            . "id: 3\nevent: done\ndata: \"completed\"\n\n";

        $client = $this->piDevClientReturning($this->currentApp, $this->currentCompany, [
            new Response(200, ['Content-Type' => 'text/event-stream'], $sse),
        ]);

        $frames = $client->fetchJobEvents('job-1', afterId: 2);

        $this->assertCount(1, $frames);
        $this->assertSame(3, $frames[0]['id']);
    }

    public function testValidateCredentialsTreatsMissingJobAsAuthorized(): void
    {
        $mock = new GuzzleClient([
            'handler' => HandlerStack::create(new MockHandler([
                new Response(200, [], '{"status":"ok"}'),
                new Response(404, [], '{"error":"job not found"}'),
            ])),
        ]);

        $this->assertTrue(Client::validateCredentials('https://pidev.test', 'token', $mock));
    }

    public function testValidateCredentialsRejectsBadToken(): void
    {
        $mock = new GuzzleClient([
            'handler' => HandlerStack::create(new MockHandler([
                new Response(200, [], '{"status":"ok"}'),
                new Response(401, [], '{"error":"unauthorized"}'),
            ])),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('pi.dev API token is invalid');

        Client::validateCredentials('https://pidev.test', 'bad-token', $mock);
    }
}
