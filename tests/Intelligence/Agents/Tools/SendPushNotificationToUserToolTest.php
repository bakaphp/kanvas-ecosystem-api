<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\SendPushNotificationToUserTool;
use Kanvas\Intelligence\Notifications\AgentPushNotification;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class SendPushNotificationToUserToolTest extends TestCase
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

    public function testSendsAPushNotificationToACompanyUser(): void
    {
        Notification::fake();

        $result = new SendPushNotificationToUserTool($this->makeAgent())->__invoke(
            recipient_email: $this->user->email,
            title: 'Report ready',
            message: 'The weekly sales report just finished.',
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame($this->user->email, $result['to']);

        Notification::assertSentTo(
            $this->user,
            AgentPushNotification::class,
            function (AgentPushNotification $notification): bool {
                $push = $notification->toOneSignal($this->user);

                return $notification->channels === ['push', 'expo']
                    && $push['title'] === 'Report ready'
                    && $push['message'] === 'The weekly sales report just finished.';
            }
        );
    }

    public function testRejectsAnEmailThatIsNotACompanyUser(): void
    {
        Notification::fake();

        $result = new SendPushNotificationToUserTool($this->makeAgent())->__invoke(
            recipient_email: 'stranger-' . uniqid() . '@example.com',
            title: 'Hi',
            message: 'Anyone there?',
        );

        $this->assertSame('error', $result['status']);
        Notification::assertNothingSent();
    }

    public function testErrorsWithoutAnAgentInScope(): void
    {
        Notification::fake();

        $result = new SendPushNotificationToUserTool(null)->__invoke(
            recipient_email: $this->user->email,
            title: 'Hi',
            message: 'Hello',
        );

        $this->assertSame('error', $result['status']);
        Notification::assertNothingSent();
    }

    public function testRejectsAnEmptyTitleOrMessage(): void
    {
        Notification::fake();

        $result = new SendPushNotificationToUserTool($this->makeAgent())->__invoke(
            recipient_email: $this->user->email,
            title: '   ',
            message: 'Hello',
        );

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
