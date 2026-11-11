<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\BrushCrazy;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\BrushCrazy\Actions\ProvisionBrushCrazyReceiverAction;
use Kanvas\Connectors\BrushCrazy\Enums\ConfigurationEnum;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Connectors\BrushCrazy\Webhooks\ProcessBrushCrazyWebhookJob;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

final class ProvisionBrushCrazyReceiverActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow'];

    protected function setUp(): void
    {
        parent::setUp();

        WorkflowAction::firstOrCreate(
            ['model_name' => ProcessBrushCrazyWebhookJob::class],
            ['name' => 'BrushCrazy Mirror Webhook'],
        );
    }

    public function testCreatesAReceiverWithASigningSecret(): void
    {
        $action = $this->action();
        $receiver = $action->execute();

        $this->assertTrue($action->created);
        $this->assertSame(64, strlen((string) $receiver->configuration['signing_secret']));
        $this->assertSame(2, $receiver->configuration['brushcrazy_studio_id']);
        $this->assertTrue($receiver->run_async);
    }

    /**
     * BrushCrazy keeps signing with the secret it was given; a re-run that rotated it would make
     * every later delivery fail authentication.
     */
    public function testReRunKeepsTheSameReceiverAndSecret(): void
    {
        $first = $this->action()->execute();

        $again = $this->action();
        $second = $again->execute();

        $this->assertFalse($again->created);
        $this->assertSame($first->getId(), $second->getId());
        $this->assertSame($first->uuid, $second->uuid);
        $this->assertSame($first->configuration['signing_secret'], $second->configuration['signing_secret']);
    }

    /**
     * The webhook drops every pointer for a company without the flag, so creation turns it on; a
     * re-run must not turn back on a studio that was switched off for its cutover.
     */
    public function testCreationEnablesSyncButAReRunNeverReEnablesIt(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $key = ConfigurationEnum::BRUSHCRAZY_SYNC_ENABLED->value;
        $original = $company->get($key);

        try {
            $company->del($key);
            $this->action()->execute();
            $this->assertTrue($company->getBool($key));

            $company->set($key, false);
            $this->action()->execute();
            $this->assertFalse($company->getBool($key));
        } finally {
            $original !== null ? $company->set($key, $original) : $company->del($key);
        }
    }

    public function testOnlyACorrectSignatureAuthenticates(): void
    {
        $receiver = $this->action()->execute();
        $secret = (string) $receiver->configuration['signing_secret'];
        $body = json_encode(['events' => [['entity_type' => 'lesson', 'entity_id' => 1]]]);
        $timestamp = (string) time();

        $this->assertTrue(ProcessBrushCrazyWebhookJob::authenticateRequest(
            $this->signedRequest($body, $timestamp, ProcessBrushCrazyWebhookJob::signature($timestamp, $body, $secret)),
            $receiver
        ));
        $this->assertFalse(ProcessBrushCrazyWebhookJob::authenticateRequest(
            $this->signedRequest($body, $timestamp, ProcessBrushCrazyWebhookJob::signature($timestamp, $body, 'another-secret')),
            $receiver
        ));
        $this->assertFalse(ProcessBrushCrazyWebhookJob::authenticateRequest(
            $this->signedRequest($body . ' ', $timestamp, ProcessBrushCrazyWebhookJob::signature($timestamp, $body, $secret)),
            $receiver
        ));
        $this->assertFalse(ProcessBrushCrazyWebhookJob::authenticateRequest(
            $this->signedRequest($body, $timestamp, null),
            $receiver
        ));
    }

    private function action(): ProvisionBrushCrazyReceiverAction
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return new ProvisionBrushCrazyReceiverAction(
            app(Apps::class),
            $user,
            new StudioContext(
                bcStudioId: 2,
                company: $company,
                branch: $company->defaultBranch,
                themeArea: new ThemeArea(),
                timezone: 'America/New_York',
            ),
        );
    }

    private function signedRequest(string $body, string $timestamp, ?string $signature): Request
    {
        $server = $signature !== null ? ['HTTP_X_BRUSHCRAZY_SIGNATURE' => $signature] : [];
        $server['HTTP_X_BRUSHCRAZY_TIMESTAMP'] = $timestamp;

        return Request::create(
            'https://localhost/v1/receiver/test',
            'POST',
            server: $server + ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        );
    }
}
