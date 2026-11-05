<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\VAuto;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\VAuto\Activities\PushAppraisalActivity;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\AppModuleMessage;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasVAutoConfiguration;
use Tests\TestCase;

/**
 * Every path here stops before the SOAP call. The push itself is covered by PushAppraisalActionTest.
 */
final class PushAppraisalActivityTest extends TestCase
{
    use DatabaseTransactions;
    use HasVAutoConfiguration;

    protected $connectionsToTransact = [null, 'crm', 'social'];

    public function testSkipsQuietlyWhenTheCompanyHasNoVAutoConfiguration(): void
    {
        $message = $this->message(Companies::factory()->create(), ['verb' => 'trade-walk', 'status' => 'submitted']);

        $result = $this->activity()->execute($message, app(Apps::class), []);

        $this->assertStringContainsString('not configured', $result['message']);
    }

    public function testFailsTheWorkflowWhenTheMessageHasNoLeadInsteadOfThrowing(): void
    {
        $message = $this->message($this->vAutoCompany(), ['verb' => 'trade-walk', 'status' => 'submitted']);

        $result = $this->activity()->execute($message, app(Apps::class), []);

        $this->assertSame('Message is not associated with a Lead', $result['error']);
    }

    public function testIgnoresMessagesThatAreNotASubmittedTradeIn(): void
    {
        $company = $this->vAutoCompany();
        $payloads = [
            ['verb' => 'trade-walk', 'status' => 'open'],
            ['verb' => 'credit-app', 'status' => 'submitted'],
        ];

        foreach ($payloads as $payload) {
            $result = $this->activity()->execute($this->leadMessage($company, $payload), app(Apps::class), []);

            $this->assertSame('Not a submitted trade-in, skipping', $result['message'], json_encode($payload));
        }
    }

    private function activity(): PushAppraisalActivity
    {
        return new PushAppraisalActivity(
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            []
        );
    }

    private function message(Companies $company, array $payload): Message
    {
        return Message::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($company->getId())
            ->create(['message' => $payload + ['data' => ['form' => []]]]);
    }

    private function leadMessage(Companies $company, array $payload): Message
    {
        $lead = Lead::factory()
            ->withAppAndCompany(app(Apps::class)->getId(), $company->getId())
            ->create();
        $message = $this->message($company, $payload);

        AppModuleMessage::create([
            'message_id' => $message->getId(),
            'message_types_id' => $message->message_types_id,
            'apps_id' => $message->apps_id,
            'companies_id' => $message->companies_id,
            'system_modules' => Lead::class,
            'entity_id' => $lead->getId(),
            'is_deleted' => 0,
        ]);

        return $message->fresh();
    }
}
