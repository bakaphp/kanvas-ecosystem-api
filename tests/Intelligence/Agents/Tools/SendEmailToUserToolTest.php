<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Support\Facades\Mail;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\SendEmailToUserTool;
use Kanvas\Notifications\KanvasMailable;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class SendEmailToUserToolTest extends TestCase
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

    public function testEmailsACompanyTeammate(): void
    {
        Mail::fake();

        $result = new SendEmailToUserTool($this->makeAgent())->__invoke(
            recipient_email: $this->user->email,
            subject: 'Standup notes',
            body: 'Here is the **summary** from today.',
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame($this->user->email, $result['to']);

        Mail::assertSent(
            KanvasMailable::class,
            fn (KanvasMailable $mail): bool => $mail->hasTo($this->user->email)
                && $mail->subject === 'Standup notes'
        );
    }

    public function testRejectsAnEmailThatIsNotATeammate(): void
    {
        Mail::fake();

        $result = new SendEmailToUserTool($this->makeAgent())->__invoke(
            recipient_email: 'stranger-' . uniqid() . '@example.com',
            subject: 'Hi',
            body: 'Anyone there?',
        );

        $this->assertSame('error', $result['status']);
        Mail::assertNothingSent();
    }

    public function testErrorsWithoutAnAgentInScope(): void
    {
        Mail::fake();

        $result = new SendEmailToUserTool(null)->__invoke(
            recipient_email: $this->user->email,
            subject: 'Hi',
            body: 'Hello',
        );

        $this->assertSame('error', $result['status']);
        Mail::assertNothingSent();
    }

    public function testRejectsAnEmptySubjectOrBody(): void
    {
        Mail::fake();

        $result = new SendEmailToUserTool($this->makeAgent())->__invoke(
            recipient_email: $this->user->email,
            subject: '   ',
            body: 'Hello',
        );

        $this->assertSame('error', $result['status']);
        Mail::assertNothingSent();
    }

    private function makeAgent(): Agent
    {
        return Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['name' => 'Sofia', 'user_id' => $this->user->getId()]);
    }
}
