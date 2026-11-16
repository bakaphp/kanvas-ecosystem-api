<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Twilio;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Services\TwilioReceiverService;
use Kanvas\Connectors\Twilio\Webhooks\ProcessTwilioMessageStatusWebhookJob;
use Kanvas\Connectors\Twilio\Webhooks\ProcessTwilioWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

/**
 * Every outbound SMS (lead sends and agent replies) names this URL as its statusCallback.
 */
class TwilioReceiverServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = [null, 'workflow'];

    public function testStatusCallbackUrlIsTheCompanysActiveStatusReceiver(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $this->disableExistingReceivers($app, $company->getId());

        $this->createReceiver(ProcessTwilioWebhookJob::class);
        $statusReceiver = $this->createReceiver(ProcessTwilioMessageStatusWebhookJob::class);

        $this->assertSame(
            $statusReceiver->getUrl(),
            new TwilioReceiverService()->statusCallbackUrl($app, $company),
        );
    }

    public function testNoStatusCallbackWithoutAnActiveStatusReceiver(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $this->disableExistingReceivers($app, $company->getId());

        $this->createReceiver(ProcessTwilioWebhookJob::class);
        $this->createReceiver(ProcessTwilioMessageStatusWebhookJob::class, isActive: false);

        $this->assertNull(new TwilioReceiverService()->statusCallbackUrl($app, $company));
    }

    private function disableExistingReceivers(Apps $app, int $companyId): void
    {
        ReceiverWebhook::query()
            ->fromApp($app)
            ->fromCompany($companyId)
            ->forJob(ProcessTwilioMessageStatusWebhookJob::class)
            ->update(['is_active' => false]);
    }

    private function createReceiver(string $jobClass, bool $isActive = true): ReceiverWebhook
    {
        $user = auth()->user();
        $action = WorkflowAction::firstOrCreate(
            ['model_name' => $jobClass],
            ['name' => class_basename($jobClass)],
        );

        return ReceiverWebhook::factory()
            ->app(app(Apps::class)->getId())
            ->company($user->getCurrentCompany()->getId())
            ->user($user->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => [],
                'is_active' => $isActive,
            ]);
    }
}
