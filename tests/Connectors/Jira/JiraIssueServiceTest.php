<?php

declare(strict_types=1);

namespace Tests\Connectors\Jira;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Jira\Enums\ConfigurationEnum;
use Kanvas\Connectors\Jira\Exceptions\JiraException;
use Kanvas\Connectors\Jira\Services\JiraIssueService;
use Tests\TestCase;

final class JiraIssueServiceTest extends TestCase
{
    private const string INSTANCE_URL = 'https://kanvas.atlassian.net';

    private JiraIssueService $service;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var Apps $app */
        $app = app(Apps::class);
        /** @var Companies $company */
        $company = static::$cachedUser->getCurrentCompany();

        $company->set(ConfigurationEnum::INSTANCE_URL->value, self::INSTANCE_URL);
        $company->set(ConfigurationEnum::EMAIL->value, 'agent@kanvas.test');
        $company->set(ConfigurationEnum::API_TOKEN->value, 'test-api-token');

        $this->service = JiraIssueService::forApp($app, $company);
    }

    public function testToDocumentFormatWrapsPlainTextInAtlassianDocumentFormat(): void
    {
        $document = JiraIssueService::toDocumentFormat('Hello Jira');

        $this->assertSame('doc', $document['type']);
        $this->assertSame(1, $document['version']);
        $this->assertSame('paragraph', $document['content'][0]['type']);
        $this->assertSame('Hello Jira', $document['content'][0]['content'][0]['text']);
    }

    public function testCreateIssueSendsProjectSummaryAndDescription(): void
    {
        Http::fake([
            self::INSTANCE_URL . '/rest/api/3/issue' => Http::response(['id' => '10001', 'key' => 'OPS-1']),
        ]);

        $issue = $this->service->createIssue('OPS', 'Investigate outage', 'Customer reported downtime');

        $this->assertSame('OPS-1', $issue['key']);

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $body['fields']['project']['key'] === 'OPS'
                && $body['fields']['summary'] === 'Investigate outage'
                && $body['fields']['issuetype']['name'] === 'Task'
                && $body['fields']['description']['content'][0]['content'][0]['text'] === 'Customer reported downtime';
        });
    }

    public function testGetTransitionsReturnsTheTransitionsArray(): void
    {
        Http::fake([
            self::INSTANCE_URL . '/rest/api/3/issue/OPS-1/transitions' => Http::response([
                'transitions' => [
                    ['id' => '11', 'name' => 'In Progress'],
                    ['id' => '31', 'name' => 'Done'],
                ],
            ]),
        ]);

        $transitions = $this->service->getTransitions('OPS-1');

        $this->assertCount(2, $transitions);
        $this->assertSame('Done', $transitions[1]['name']);
    }

    public function testTransitionIssueResolvesTransitionIdByName(): void
    {
        Http::fake([
            self::INSTANCE_URL . '/rest/api/3/issue/OPS-1/transitions' => Http::sequence()
                ->push([
                    'transitions' => [
                        ['id' => '11', 'name' => 'In Progress'],
                        ['id' => '31', 'name' => 'Done'],
                    ],
                ])
                ->push([], 204),
        ]);

        $this->service->transitionIssue('OPS-1', 'done');

        Http::assertSent(
            fn (Request $request): bool => $request->method() === 'POST'
                && ($request->data()['transition']['id'] ?? null) === '31'
        );
    }

    public function testTransitionIssueThrowsWhenTheNamedTransitionDoesNotExist(): void
    {
        Http::fake([
            self::INSTANCE_URL . '/rest/api/3/issue/OPS-1/transitions' => Http::response([
                'transitions' => [
                    ['id' => '11', 'name' => 'In Progress'],
                ],
            ]),
        ]);

        $this->expectException(JiraException::class);
        $this->expectExceptionMessage('no transition named "Done"');

        $this->service->transitionIssue('OPS-1', 'Done');
    }

    public function testAddWorklogSendsTimeSpentAndComment(): void
    {
        Http::fake([
            self::INSTANCE_URL . '/rest/api/3/issue/OPS-1/worklog' => Http::response(['id' => 'wl-1']),
        ]);

        $worklog = $this->service->addWorklog('OPS-1', '2h', 'Investigated root cause');

        $this->assertSame('wl-1', $worklog['id']);
        Http::assertSent(
            fn (Request $request): bool => $request->data()['timeSpent'] === '2h'
                && ($request->data()['comment']['content'][0]['content'][0]['text'] ?? null) === 'Investigated root cause'
        );
    }
}
