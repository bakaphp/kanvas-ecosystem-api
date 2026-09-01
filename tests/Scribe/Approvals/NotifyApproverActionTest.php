<?php

declare(strict_types=1);

namespace Tests\Scribe\Approvals;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\AgentRuntime\Enums\AgentChannelTokenEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Scribe\Approvals\Actions\NotifyApproverAction;
use Kanvas\Scribe\Approvals\Enums\ApprovalConfigurationEnum;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class NotifyApproverActionTest extends TestCase
{
    use DatabaseTransactions;

    private const string APPROVER_EMAIL = 'approver@example.test';

    private Apps $kanvasApp;
    private Companies $company;
    private Users $user;
    private mixed $originalNotifierAgentId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();

        // HasCustomFields writes through Redis, which DatabaseTransactions never rolls back —
        // save/restore explicitly so a value set here can't leak into the next test in the run.
        $this->originalNotifierAgentId = $this->kanvasApp->get(ApprovalConfigurationEnum::SLACK_NOTIFIER_AGENT_ID->value);
        $this->kanvasApp->set(ApprovalConfigurationEnum::SLACK_NOTIFIER_AGENT_ID->value, '');
    }

    protected function tearDown(): void
    {
        $this->kanvasApp->set(ApprovalConfigurationEnum::SLACK_NOTIFIER_AGENT_ID->value, $this->originalNotifierAgentId);

        parent::tearDown();
    }

    public function test_it_uploads_the_pdf_as_a_real_attachment_when_a_url_is_present(): void
    {
        $this->configureNotifierAgent();
        $this->fakeSlackAndAttachment();

        new NotifyApproverAction(
            app: $this->kanvasApp,
            text: 'You have an AP bill pending approval',
            approverEmail: self::APPROVER_EMAIL,
            attachmentUrl: 'https://cdn.example.test/invoice-4521.pdf',
            attachmentFilename: 'invoice-4521.pdf',
        )->execute();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'files.getUploadURLExternal'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'files.completeUploadExternal'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'chat.postMessage'));
    }

    public function test_it_sends_a_plain_message_when_there_is_no_attachment(): void
    {
        $this->configureNotifierAgent();
        $this->fakeSlackAndAttachment();

        new NotifyApproverAction($this->kanvasApp, 'You have an AP bill pending approval', self::APPROVER_EMAIL)->execute();

        Http::assertSent(
            fn (Request $request): bool => str_contains($request->url(), 'chat.postMessage')
                && $request['text'] === 'You have an AP bill pending approval'
        );
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'files.getUploadURLExternal'));
    }

    public function test_it_falls_back_to_a_plain_message_when_the_upload_fails(): void
    {
        $this->configureNotifierAgent();
        Http::fake([
            'slack.com/api/users.lookupByEmail' => Http::response(['ok' => true, 'user' => ['id' => 'U123']]),
            'slack.com/api/conversations.open' => Http::response(['ok' => true, 'channel' => ['id' => 'D123']]),
            'cdn.example.test/*' => Http::response('', 404),
            'slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'ts' => '1700000000.000100']),
        ]);

        new NotifyApproverAction(
            app: $this->kanvasApp,
            text: 'You have an AP bill pending approval',
            approverEmail: self::APPROVER_EMAIL,
            attachmentUrl: 'https://cdn.example.test/invoice-4521.pdf',
        )->execute();

        Http::assertSent(
            fn (Request $request): bool => str_contains($request->url(), 'chat.postMessage')
                && $request['text'] === 'You have an AP bill pending approval'
        );
    }

    public function test_it_does_nothing_when_the_approver_email_is_missing(): void
    {
        $this->configureNotifierAgent();
        Http::fake();

        new NotifyApproverAction($this->kanvasApp, 'You have an AP bill pending approval')->execute();

        Http::assertNothingSent();
    }

    public function test_it_does_nothing_when_slack_is_not_configured(): void
    {
        Http::fake();

        new NotifyApproverAction($this->kanvasApp, 'You have an AP bill pending approval', self::APPROVER_EMAIL)->execute();

        Http::assertNothingSent();
    }

    public function test_notify_all_sends_one_message_per_email(): void
    {
        $this->configureNotifierAgent();
        Http::fake([
            'slack.com/api/users.lookupByEmail' => Http::response(['ok' => true, 'user' => ['id' => 'U123']]),
            'slack.com/api/conversations.open' => Http::response(['ok' => true, 'channel' => ['id' => 'D123']]),
            'slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'ts' => '1700000000.000100']),
        ]);

        NotifyApproverAction::notifyAll(
            approverEmails: ['first@example.test', 'second@example.test'],
            app: $this->kanvasApp,
            text: 'You have an AP bill pending approval',
        );

        Http::assertSentCount(6);
    }

    public function test_notify_all_does_nothing_for_an_empty_list(): void
    {
        $this->configureNotifierAgent();
        Http::fake();

        NotifyApproverAction::notifyAll(approverEmails: [], app: $this->kanvasApp, text: 'Nothing to send');

        Http::assertNothingSent();
    }

    public function test_it_does_nothing_when_the_email_does_not_match_a_slack_workspace_member(): void
    {
        $this->configureNotifierAgent();
        Http::fake([
            'slack.com/api/users.lookupByEmail' => Http::response(['ok' => false, 'error' => 'users_not_found']),
        ]);

        new NotifyApproverAction($this->kanvasApp, 'You have an AP bill pending approval', self::APPROVER_EMAIL)->execute();

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'conversations.open'));
    }

    private function configureNotifierAgent(): void
    {
        $agent = Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['name' => 'Apex', 'user_id' => $this->user->getId()]);
        $agent->set(AgentChannelTokenEnum::SLACK_BOT_TOKEN->value, 'xoxb-test-token');

        $this->kanvasApp->set(ApprovalConfigurationEnum::SLACK_NOTIFIER_AGENT_ID->value, (string) $agent->getId());
    }

    private function fakeSlackAndAttachment(): void
    {
        Http::fake([
            'slack.com/api/users.lookupByEmail' => Http::response(['ok' => true, 'user' => ['id' => 'U123']]),
            'slack.com/api/conversations.open' => Http::response(['ok' => true, 'channel' => ['id' => 'D123']]),
            'cdn.example.test/*' => Http::response('%PDF-1.4 fake bytes', 200),
            'slack.com/api/files.getUploadURLExternal' => Http::response([
                'ok' => true,
                'upload_url' => 'https://files.slack.com/upload/v1/abc123',
                'file_id' => 'F123',
            ]),
            'files.slack.com/upload/v1/abc123' => Http::response('', 200),
            'slack.com/api/files.completeUploadExternal' => Http::response(['ok' => true]),
            'slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'ts' => '1700000000.000100']),
        ]);
    }
}
