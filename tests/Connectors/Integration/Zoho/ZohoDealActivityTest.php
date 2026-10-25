<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Zoho\Activities\ZohoDealActivity;
use Kanvas\Connectors\Zoho\Handlers\ZohoHandler;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Integration\Zoho\Concerns\BuildsZohoDeal;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

final class ZohoDealActivityTest extends TestCase
{
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

    public function testReturnsTheZohoIdWhenThePushSucceeds(): void
    {
        $deal = $this->makeDeal($this->company);
        $activity = $this->activity(['zohoDealId' => 'zd-1', 'zohoRequest' => ['Deal_Name' => $deal->title], 'recordExists' => true, 'files' => ['uploaded' => [], 'failed' => []]]);

        $result = $activity->execute($deal, app(Apps::class), []);

        $this->assertSame('zd-1', $result['zohoDealId']);
        $this->assertSame($deal->getId(), $result['dealId']);
        $this->assertNotSame(StatusEnum::FAILED, $activity->workflowStatus());
    }

    public function testAChangeThatCameFromZohoIsNotPushedBack(): void
    {
        $deal = $this->makeDeal($this->company);
        $activity = $this->activity(['zohoDealId' => 'never', 'zohoRequest' => [], 'recordExists' => true, 'files' => null]);

        $result = $activity->execute($deal, app(Apps::class), ['zoho_payload' => ['id' => 'zd-1']]);

        $this->assertFalse($activity->synced, 'the deal webhook fires the workflow with zoho_payload; pushing then would loop');
        $this->assertStringContainsString('originated in Zoho', $result['message']);
        $this->assertNotSame(StatusEnum::FAILED, $activity->workflowStatus());
    }

    public function testFailsTheWorkflowWhenZohoReturnedNoId(): void
    {
        $deal = $this->makeDeal($this->company);
        $activity = $this->activity(['zohoDealId' => null, 'zohoRequest' => [], 'recordExists' => true, 'files' => null]);

        $result = $activity->execute($deal, app(Apps::class), []);

        $this->assertSame(StatusEnum::FAILED, $activity->workflowStatus());
        $this->assertStringContainsString('not created', $result['message']);
    }

    public function testFailsTheWorkflowWhenTheZohoDealIsGone(): void
    {
        $deal = $this->makeDeal($this->company);
        $activity = $this->activity(['zohoDealId' => 'zd-9', 'zohoRequest' => [], 'recordExists' => false, 'files' => null]);

        $result = $activity->execute($deal, app(Apps::class), []);

        $this->assertSame(StatusEnum::FAILED, $activity->workflowStatus());
        $this->assertStringContainsString('no longer exists', $result['message']);
    }

    public function testRefusesAnEntityThatIsNotADeal(): void
    {
        $activity = $this->activity(['zohoDealId' => 'never', 'zohoRequest' => [], 'recordExists' => true, 'files' => null]);

        $result = $activity->execute($this->makeLead($this->company), app(Apps::class), []);

        $this->assertFalse($activity->synced);
        $this->assertSame(StatusEnum::FAILED, $activity->workflowStatus());
        $this->assertStringContainsString('only runs on a Deal', $result['message']);
    }

    private function activity(array $syncResult): ZohoDealActivity
    {
        $activity = new class (
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        ) extends ZohoDealActivity {
            public array $syncResult = [];
            public bool $synced = false;

            protected function pushDealToZoho(Deal $deal): array
            {
                $this->synced = true;

                return $this->syncResult;
            }
        };

        $activity->syncResult = $syncResult;

        return $activity;
    }
}
