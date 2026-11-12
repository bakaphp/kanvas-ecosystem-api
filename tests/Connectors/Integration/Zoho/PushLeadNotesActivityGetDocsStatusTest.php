<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Zoho\Activities\PushLeadNotesActivity;
use Kanvas\Connectors\Zoho\Handlers\ZohoHandler;
use Kanvas\Connectors\Zoho\ZohoService;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Integration\Zoho\Concerns\BuildsGetDocsMessage;
use Tests\Connectors\Integration\Zoho\Concerns\BuildsZohoDeal;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

/**
 * Only the gate is under test here: the status moves when a Get Docs submission carried files and
 * never otherwise. The move itself is covered by PushGetDocsLeadStatusActionTest.
 */
final class PushLeadNotesActivityGetDocsStatusTest extends TestCase
{
    use BuildsGetDocsMessage;
    use BuildsZohoDeal;
    use DatabaseTransactions;
    use HasIntegrationCompany;

    protected $connectionsToTransact = [null, 'crm', 'social', 'ecosystem', 'workflow'];

    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeZohoCompany();
        $this->setIntegration(
            app(Apps::class),
            IntegrationsEnum::ZOHO,
            ZohoHandler::class,
            $this->company,
            auth()->user()
        );
    }

    public function testASubmissionWithFilesMovesTheLeadStatus(): void
    {
        $lead = $this->makeLead($this->company);
        $activity = $this->activity(filesPushed: 2);

        $result = $activity->execute($this->getDocsMessage($lead, $this->company), app(Apps::class), []);

        $this->assertSame($lead->getId(), $activity->statusPushedFor?->getId());
        $this->assertSame(['moved' => true, 'status' => 'Hot'], $result['leadStatus']);
        $this->assertNotSame(StatusEnum::FAILED, $activity->workflowStatus());
    }

    public function testASubmissionWithoutFilesLeavesTheStatusAlone(): void
    {
        $lead = $this->makeLead($this->company);
        $activity = $this->activity(filesPushed: 0);

        $result = $activity->execute($this->getDocsMessage($lead, $this->company), app(Apps::class), []);

        $this->assertNull($activity->statusPushedFor);
        $this->assertNull($result['leadStatus']);
    }

    public function testAMessageThatIsNotASubmissionLeavesTheStatusAlone(): void
    {
        $lead = $this->makeLead($this->company);
        $activity = $this->activity(filesPushed: 2);

        $result = $activity->execute(
            $this->getDocsMessage($lead, $this->company, ActionStatusEnum::SENT->value),
            app(Apps::class),
            []
        );

        $this->assertNull($activity->statusPushedFor);
        $this->assertNull($result['leadStatus']);
    }

    private function activity(int $filesPushed): PushLeadNotesActivity
    {
        $activity = new class (
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            []
        ) extends PushLeadNotesActivity {
            public int $filesPushed = 0;
            public ?Lead $statusPushedFor = null;
            public ?ZohoService $fakeZohoService = null;

            protected function zohoService(Lead $lead): ZohoService
            {
                return $this->fakeZohoService;
            }

            protected function pushGetDocs(Message $message, callable $resolveTargets): array
            {
                $resolveTargets();

                return ['files' => $this->filesPushed];
            }

            protected function pushGetDocsLeadStatus(Lead $lead, ZohoService $zohoService): array
            {
                $this->statusPushedFor = $lead;

                return ['moved' => true, 'status' => 'Hot'];
            }
        };

        $activity->filesPushed = $filesPushed;
        $activity->fakeZohoService = $this->createStub(ZohoService::class);

        return $activity;
    }
}
