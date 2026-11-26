<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem\Orchestrator;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\NervousSystem\Orchestrator\Signals\Adapters\ReadAiSignalAdapter;
use Kanvas\NervousSystem\Orchestrator\Signals\Enums\SignalSourceEnum;
use Kanvas\NervousSystem\Orchestrator\Signals\Enums\SignalSourceSettingEnum;
use Kanvas\NervousSystem\Orchestrator\Webhooks\ProcessOrchestratorSignalJob;
use Kanvas\Workflow\Actions\CreateReceiverWebhookAction;
use Kanvas\Workflow\DataTransferObject\ReceiverWebhook as ReceiverWebhookData;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\Intelligence\NervousSystem\Orchestrator\Concerns\MakesMeetingRoutingFixtures;
use Tests\TestCase;

class ReadAiSignalAdapterTest extends TestCase
{
    use DatabaseTransactions;
    use MakesMeetingRoutingFixtures;

    protected array $connectionsToTransact = ['mysql', 'workflow'];

    private function companyWithKey(string $key): Companies
    {
        $company = $this->freshCompany();
        $company->setEncrypted(SignalSourceSettingEnum::READ_AI_SIGNING_KEY->value, $key);

        return $company;
    }

    private function request(string $body, ?string $signature): Request
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($signature !== null) {
            $server['HTTP_X_READ_SIGNATURE'] = $signature;
        }

        return Request::create(
            'https://localhost/v1/receiver/x',
            'POST',
            [],
            [],
            [],
            $server,
            $body,
        );
    }

    private function sign(string $body, string $key): string
    {
        return hash_hmac('sha256', $body, base64_decode($key, true));
    }

    public function testAValidSignaturePasses(): void
    {
        $key = base64_encode(random_bytes(32));
        $body = '{"session_id":"s1","trigger":"meeting_end"}';

        $request = $this->request($body, strtoupper($this->sign($body, $key)));

        $this->assertTrue(new ReadAiSignalAdapter()->authenticate($request, $this->companyWithKey($key)));
    }

    public function testAWrongOrMissingSignatureIsRefused(): void
    {
        $key = base64_encode(random_bytes(32));
        $company = $this->companyWithKey($key);
        $body = '{"session_id":"s1","trigger":"meeting_end"}';
        $adapter = new ReadAiSignalAdapter();

        $this->assertFalse($adapter->authenticate($this->request($body, null), $company));
        $this->assertFalse($adapter->authenticate($this->request($body, $this->sign($body . ' ', $key)), $company));
        $this->assertFalse($adapter->authenticate($this->request($body, $this->sign($body, base64_encode('other'))), $company));
    }

    public function testACompanyWithNoKeyIsAcceptedAndMarkedUnsigned(): void
    {
        $company = $this->freshCompany();
        $adapter = new ReadAiSignalAdapter();

        $this->assertTrue($adapter->authenticate($this->request('{}', null), $company));
        $this->assertFalse($adapter->isSigned($company));
        $this->assertTrue($adapter->isSigned($this->companyWithKey(base64_encode('k'))));
    }

    public function testAMeetingStartCarriesNoContent(): void
    {
        $payload = [
            'session_id' => 's1',
            'trigger' => 'meeting_start',
            'title' => 'Kickoff',
            'owner' => ['name' => 'Max', 'email' => 'max@ourco.io'],
        ];

        $this->assertSame('', new ReadAiSignalAdapter()->parse($payload)->content);
        $this->assertNull(SignalSourceEnum::parseWithFallback($payload, SignalSourceEnum::READ_AI));
    }

    public function testSummaryReportUrlAndChaptersAreCarried(): void
    {
        $signal = new ReadAiSignalAdapter()->parse([
            'session_id' => 's1',
            'trigger' => 'meeting_end',
            'title' => 'Pricing',
            'summary' => 'They want the pro plan.',
            'report_url' => 'https://app.read.ai/analytics/meetings/s1',
            'chapter_summaries' => [
                ['title' => 'Pricing', 'description' => 'Discount asked.'],
                ['title' => '', 'description' => ''],
            ],
            'transcript' => ['speaker_blocks' => [['speaker' => ['name' => 'Ana'], 'words' => 'Hello']]],
        ]);

        $this->assertSame('They want the pro plan.', $signal->metadata['summary']);
        $this->assertSame('https://app.read.ai/analytics/meetings/s1', $signal->metadata['report_url']);
        $this->assertSame(['Pricing: Discount asked.'], $signal->metadata['chapters']);
    }

    public function testTheReceiverRefusesAForgedRequestAndAcceptsASignedOne(): void
    {
        Queue::fake();

        $key = base64_encode(random_bytes(32));
        $receiver = $this->receiver($this->companyWithKey($key));
        $body = (string) json_encode(['session_id' => 's1', 'trigger' => 'meeting_end', 'title' => 'Call']);
        $uri = '/v1/receiver/' . $receiver->uuid;

        $this->call(
            'POST',
            $uri,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_READ_SIGNATURE' => 'forged'],
            $body,
        )
            ->assertStatus(401);

        $this->call(
            'POST',
            $uri,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_READ_SIGNATURE' => $this->sign($body, $key)],
            $body,
        )
            ->assertSuccessful();

        Queue::assertPushed(ProcessOrchestratorSignalJob::class, 1);
    }

    private function receiver(Companies $company): ReceiverWebhook
    {
        $action = WorkflowAction::firstOrCreate(['model_name' => ProcessOrchestratorSignalJob::class], ['name' => 'Orchestrator Signal Ingest']);

        return new CreateReceiverWebhookAction(
            new ReceiverWebhookData(
                app: app(Apps::class),
                company: $company,
                user: $this->testUser(),
                action: $action,
                name: 'Orchestrator ingest',
                description: 'Read.ai signature test',
                configuration: ['signal_source' => 'read_ai'],
                is_active: true,
                run_async: true,
            ),
        )->execute();
    }
}
