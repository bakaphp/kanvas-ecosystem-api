<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\SendPushNotificationTool;
use Kanvas\Intelligence\Notifications\AgentPushNotification;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class SendPushNotificationToolTest extends TestCase
{
    use DatabaseTransactions;

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

    public function testSendsAPushNotificationToTheConversationHuman(): void
    {
        Notification::fake();

        // The human the agent is talking to — the session subject — is a DIFFERENT user than the
        // agent's own context user. The push must land on the human, not the agent.
        $human = Users::factory()->create();
        $session = new Session();
        $session->entity_namespace = Users::class;
        $session->entity_id = $human->getId();

        $result = new SendPushNotificationTool($this->makeAgent(), $session)
            ->withContext($this->kanvasApp, $this->company, $this->user)
            ->__invoke(title: 'Report ready', message: 'The weekly sales report just finished.');

        $this->assertSame('success', $result['status']);
        $this->assertSame($human->email, $result['to']);

        Notification::assertSentTo(
            $human,
            AgentPushNotification::class,
            function (AgentPushNotification $notification) use ($human): bool {
                $push = $notification->toOneSignal($human);

                return $notification->channels === ['push', 'expo']
                    && $push['title'] === 'Report ready'
                    && $push['message'] === 'The weekly sales report just finished.';
            }
        );
        Notification::assertNotSentTo($this->user, AgentPushNotification::class);
    }

    public function testFallsBackToTheContextUserWithoutASession(): void
    {
        Notification::fake();

        $result = new SendPushNotificationTool($this->makeAgent())
            ->withContext($this->kanvasApp, $this->company, $this->user)
            ->__invoke(title: 'Reminder', message: 'Time to review the deals board.');

        $this->assertSame('success', $result['status']);
        $this->assertSame($this->user->email, $result['to']);
        Notification::assertSentTo($this->user, AgentPushNotification::class);
    }

    public function testErrorsWithoutAnAgentInScope(): void
    {
        Notification::fake();

        $result = new SendPushNotificationTool(null)->__invoke(title: 'Hi', message: 'Hello');

        $this->assertSame('error', $result['status']);
        Notification::assertNothingSent();
    }

    public function testErrorsWhenNoUserCanBeResolved(): void
    {
        Notification::fake();

        // No session and no context — nobody to notify.
        $result = new SendPushNotificationTool($this->makeAgent())->__invoke(title: 'Hi', message: 'Hello');

        $this->assertSame('error', $result['status']);
        Notification::assertNothingSent();
    }

    public function testRejectsAnEmptyTitleOrMessage(): void
    {
        Notification::fake();

        $result = new SendPushNotificationTool($this->makeAgent())
            ->withContext($this->kanvasApp, $this->company, $this->user)
            ->__invoke(title: '   ', message: 'Hello');

        $this->assertSame('error', $result['status']);
        Notification::assertNothingSent();
    }

    private function makeAgent(): Agent
    {
        return Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['name' => 'Sofia', 'user_id' => $this->user->getId()]);
    }
}
