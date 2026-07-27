<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\AgentRuntime\Enums\AgentChannelTokenEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\SendSlackDirectMessageTool;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class SendSlackDirectMessageToolTest extends TestCase
{
    private Apps $kanvasApp;
    private Companies $company;
    private Users $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();
    }

    public function testSendsADirectMessageToACompanyTeammate(): void
    {
        $this->fakeSlack(slackUserFound: true);
        $agent = $this->connectedAgent();

        $result = new SendSlackDirectMessageTool($agent)->__invoke(
            recipient_email: $this->user->email,
            message: 'Standup moved to 10am.',
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame($this->user->email, $result['to']);

        Http::assertSent(
            fn (Request $request): bool => str_contains($request->url(), 'chat.postMessage')
                && $request['channel'] === 'D123'
                && $request['text'] === 'Standup moved to 10am.'
        );
    }

    public function testRejectsAnEmailThatIsNotATeammate(): void
    {
        $this->fakeSlack(slackUserFound: true);
        $agent = $this->connectedAgent();

        $result = new SendSlackDirectMessageTool($agent)->__invoke(
            recipient_email: 'stranger-' . uniqid() . '@example.com',
            message: 'Hello?',
        );

        $this->assertSame('error', $result['status']);
        Http::assertNothingSent();
    }

    public function testErrorsWhenTheTeammateIsNotInTheSlackWorkspace(): void
    {
        $this->fakeSlack(slackUserFound: false);
        $agent = $this->connectedAgent();

        $result = new SendSlackDirectMessageTool($agent)->__invoke(
            recipient_email: $this->user->email,
            message: 'Are you here?',
        );

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Slack workspace', $result['message']);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'chat.postMessage'));
    }

    public function testErrorsWithoutAnAgentInScope(): void
    {
        $result = new SendSlackDirectMessageTool(null)->__invoke(
            recipient_email: $this->user->email,
            message: 'Hi',
        );

        $this->assertSame('error', $result['status']);
    }

    public function testRejectsAnEmptyMessage(): void
    {
        $agent = $this->connectedAgent();

        $result = new SendSlackDirectMessageTool($agent)->__invoke(
            recipient_email: $this->user->email,
            message: '   ',
        );

        $this->assertSame('error', $result['status']);
    }

    private function connectedAgent(): Agent
    {
        $agent = Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['name' => 'Sofia', 'user_id' => $this->user->getId()]);

        $agent->set(AgentChannelTokenEnum::SLACK_BOT_TOKEN->value, 'xoxb-real-token');

        return $agent;
    }

    private function fakeSlack(bool $slackUserFound): void
    {
        Http::fake([
            'slack.com/api/users.lookupByEmail' => Http::response(
                $slackUserFound
                    ? ['ok' => true, 'user' => ['id' => 'U123']]
                    : ['ok' => false, 'error' => 'users_not_found']
            ),
            'slack.com/api/conversations.open' => Http::response(['ok' => true, 'channel' => ['id' => 'D123']]),
            'slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'ts' => '1700000000.000100']),
        ]);
    }
}
