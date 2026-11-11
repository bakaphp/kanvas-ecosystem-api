<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Baka\Contracts\AppInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Zoho\Actions\PushFilesToZohoRecordAction;
use Kanvas\Connectors\Zoho\Actions\SyncLeadToZohoAction;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\Connectors\Integration\Zoho\Concerns\BuildsZohoDeal;
use Tests\Connectors\Integration\Zoho\Support\RecordingFilePusher;
use Tests\TestCase;
use Webleit\ZohoCrmApi\Models\Record;
use Webleit\ZohoCrmApi\Modules\Records as ZohoRecordsModule;

/**
 * Zoho answers an empty record for a converted lead, the same as for a deleted one. The push must
 * tell them apart: a converted lead's files go to its deal and the Kanvas lead is left alone.
 */
final class ZohoConvertedLeadPushTest extends TestCase
{
    use BuildsZohoDeal;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'social', 'ecosystem'];

    private Companies $company;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeZohoCompany();
        $this->lead = $this->makeLead($this->company);
        $this->lead->set(CustomFieldEnum::ZOHO_LEAD_ID->value, 'zl-77');
        $this->lead->addFileFromUrl('https://example.com/zoho/application.pdf', 'application.pdf');
    }

    public function testAConvertedLeadSendsItsFilesToTheDealAndStaysAsItIs(): void
    {
        $statusBefore = (int) $this->lead->leads_status_id;
        $leadModule = $this->goneLeadModule();
        $deal = $this->makeDeal($this->company, lead: $this->lead);
        $deal->set(CustomFieldEnum::ZOHO_DEAL_ID->value, 'zd-77');

        $action = $this->action($leadModule, $deal);
        $action->execute();

        $fileIds = $this->lead->files()->pluck('filesystem.id')->map(intval(...))->all();
        $this->assertSame(['zd-77'], $action->pushedTo);
        $this->assertSame(
            $fileIds,
            array_values($this->lead->fresh()->get(CustomFieldEnum::ZOHO_LEAD_SYNC_FILES->value))
        );
        $this->assertSame($statusBefore, (int) $this->lead->fresh()->leads_status_id);

        $this->assertSame(
            $fileIds,
            $deal->files()->pluck('filesystem.id')->map(intval(...))->all(),
            'the same file row is attached to the Kanvas deal, no second copy'
        );
        $this->assertSame(['application.pdf'], $this->attachedFileNames($deal));
        $this->assertSame(
            $fileIds,
            array_values($deal->fresh()->get(CustomFieldEnum::ZOHO_DEAL_SYNC_FILES->value)),
            'the deal push must not send it up again'
        );
    }

    public function testASecondPushMirrorsNothingTwice(): void
    {
        $deal = $this->makeDeal($this->company, lead: $this->lead);
        $deal->set(CustomFieldEnum::ZOHO_DEAL_ID->value, 'zd-77');

        $this->action($this->goneLeadModule(), $deal)->execute();
        $this->action($this->goneLeadModule(), $deal)->execute();

        $this->assertCount(1, $deal->files()->get());
    }

    public function testALeadZohoDeletedIsStillClosed(): void
    {
        $action = $this->action($this->goneLeadModule(), convertedDeal: null);
        $action->execute();

        $this->assertSame(6, (int) $this->lead->fresh()->leads_status_id);
        $this->assertSame(['zl-77'], $action->pushedTo);
    }

    private function goneLeadModule(): ZohoRecordsModule
    {
        $module = $this->module();
        $module->method('get')->with('zl-77')->willReturn(new Record([], $module));
        $module->expects($this->never())->method('create');
        $module->expects($this->never())->method('update');

        return $module;
    }

    private function action(ZohoRecordsModule $leadModule, ?Deal $convertedDeal): SyncLeadToZohoAction
    {
        return new class (
            app(Apps::class),
            $this->lead,
            $leadModule,
            $this->createStub(ZohoRecordsModule::class),
            $convertedDeal
        ) extends SyncLeadToZohoAction {
            /** @var list<string> */
            public array $pushedTo = [];

            public function __construct(
                AppInterface $app,
                Lead $lead,
                private ZohoRecordsModule $leadModuleStub,
                private ZohoRecordsModule $dealModuleStub,
                private ?Deal $convertedDeal
            ) {
                parent::__construct($app, $lead);
            }

            protected function leadModule(Companies $company): ZohoRecordsModule
            {
                return $this->leadModuleStub;
            }

            protected function dealModule(Companies $company): ZohoRecordsModule
            {
                return $this->dealModuleStub;
            }

            protected function pullConvertedLeadDeal(Lead $lead, Companies $company): ?Deal
            {
                return $this->convertedDeal;
            }

            protected function filePusher(ZohoRecordsModule $module, string $zohoRecordId): PushFilesToZohoRecordAction
            {
                $this->pushedTo[] = $zohoRecordId;

                return new RecordingFilePusher($module, $zohoRecordId);
            }
        };
    }
}
