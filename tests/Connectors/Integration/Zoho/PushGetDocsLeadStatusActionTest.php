<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Zoho\Actions\PushGetDocsLeadStatusAction;
use Kanvas\Connectors\Zoho\DataTransferObject\ZohoLead;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadStatus;
use RuntimeException;
use Tests\Connectors\Integration\Zoho\Concerns\BuildsZohoDeal;
use Tests\TestCase;
use Webleit\ZohoCrmApi\Models\Record;
use Webleit\ZohoCrmApi\Modules\Records as ZohoRecordsModule;

final class PushGetDocsLeadStatusActionTest extends TestCase
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
        $this->lead->set(CustomFieldEnum::ZOHO_LEAD_ID->value, 'zl-100');
        $this->moveLeadTo($this->makeStatus('New Lead', open: true));
    }

    public function testMovesTheLeadInKanvasAndZohoWhenTheCompanyNamesAStatus(): void
    {
        $this->company->set(CustomFieldEnum::ZOHO_GET_DOCS_LEAD_STATUS->value, 'Hot');
        $module = $this->module();
        $module->expects($this->once())
            ->method('update')
            ->with('zl-100', ['Lead_Status' => 'Hot'])
            ->willReturn(new Record(['id' => 'zl-100'], $module));

        $result = new PushGetDocsLeadStatusAction($this->lead, $module)->execute();

        $this->assertTrue($result['moved']);
        $lead = $this->lead->fresh();
        $status = $lead->status()->firstOrFail();
        $this->assertSame('hot', strtolower($status->name));
        $this->assertTrue($status->is_open, 'receiving documents must not close the lead');
        $this->assertSame(
            'Hot',
            ZohoLead::fromLead($lead)->toArray()['Lead_Status'],
            'the next regular push must keep sending the same label, not revert to New Lead'
        );
    }

    public function testCreatesAnOpenCompanyStatusWhenNoneMatchesTheLabel(): void
    {
        $label = 'Docs Received ' . uniqid();
        $this->company->set(CustomFieldEnum::ZOHO_GET_DOCS_LEAD_STATUS->value, $label);
        new PushGetDocsLeadStatusAction($this->lead, $this->acceptingModule())->execute();

        $status = $this->lead->fresh()->status()->firstOrFail();
        $this->assertSame($label, $status->name);
        $this->assertSame((int) $this->company->getId(), (int) $status->companies_id);
        $this->assertTrue($status->is_open, 'a label the open-name list does not know must still be open');
        $this->assertSame($label, $status->get(CustomFieldEnum::ZOHO_STATUS_NAME->value));
    }

    public function testAClosedRowWithTheSameNameIsNotReused(): void
    {
        $label = 'Docs Received ' . uniqid();
        $closed = $this->makeStatus($label, open: false);
        $this->company->set(CustomFieldEnum::ZOHO_GET_DOCS_LEAD_STATUS->value, $label);
        new PushGetDocsLeadStatusAction($this->lead, $this->acceptingModule())->execute();

        $lead = $this->lead->fresh();
        $this->assertNotSame($closed->getId(), (int) $lead->leads_status_id);
        $this->assertTrue($lead->hasOpenLeadStatus());
    }

    public function testReusesTheCompanysOwnStatusRowAndLabelsItForZoho(): void
    {
        $existing = $this->makeStatus('hot', open: true);
        $this->company->set(CustomFieldEnum::ZOHO_GET_DOCS_LEAD_STATUS->value, 'Hot');
        new PushGetDocsLeadStatusAction($this->lead, $this->acceptingModule())->execute();

        $this->assertSame($existing->getId(), (int) $this->lead->fresh()->leads_status_id);
        $this->assertSame('Hot', $existing->get(CustomFieldEnum::ZOHO_STATUS_NAME->value));
        $this->assertSame(1, $this->companyStatusesNamed('hot')->count(), 'no second row for the same status');
    }

    public function testASharedRowWithoutTheZohoLabelIsNeverRelabelledFromOneTenantsSetting(): void
    {
        $label = 'Docs Received ' . uniqid();
        $shared = LeadStatus::create([
            'apps_id' => 0,
            'companies_id' => 0,
            'name' => $label,
            'is_default' => 0,
            'is_open' => true,
        ]);
        $this->company->set(CustomFieldEnum::ZOHO_GET_DOCS_LEAD_STATUS->value, $label);
        new PushGetDocsLeadStatusAction($this->lead, $this->acceptingModule())->execute();

        $status = $this->lead->fresh()->status()->firstOrFail();
        $this->assertNotSame($shared->getId(), $status->getId(), 'a platform row is not this tenant\'s to label');
        $this->assertNull($shared->get(CustomFieldEnum::ZOHO_STATUS_NAME->value));
        $this->assertSame((int) $this->company->getId(), (int) $status->companies_id);
        $this->assertSame($label, $status->get(CustomFieldEnum::ZOHO_STATUS_NAME->value));
    }

    public function testASharedRowAlreadyCarryingTheLabelIsReused(): void
    {
        $label = 'Docs Received ' . uniqid();
        $shared = LeadStatus::create([
            'apps_id' => 0,
            'companies_id' => 0,
            'name' => $label,
            'is_default' => 0,
            'is_open' => true,
        ]);
        $shared->set(CustomFieldEnum::ZOHO_STATUS_NAME->value, $label);
        $this->company->set(CustomFieldEnum::ZOHO_GET_DOCS_LEAD_STATUS->value, $label);
        new PushGetDocsLeadStatusAction($this->lead, $this->acceptingModule())->execute();

        $this->assertSame($shared->getId(), (int) $this->lead->fresh()->leads_status_id);
        $this->assertSame(0, $this->companyStatusesNamed($label)->count(), 'no company copy of a row that already works');
    }

    public function testDoesNothingWhenTheCompanyHasNoStatusConfigured(): void
    {
        $module = $this->module();
        $module->expects($this->never())->method('update');
        $before = (int) $this->lead->leads_status_id;

        $result = new PushGetDocsLeadStatusAction($this->lead, $module)->execute();

        $this->assertSame(['moved' => false, 'reason' => 'not_configured'], $result);
        $this->assertSame($before, (int) $this->lead->fresh()->leads_status_id);
    }

    public function testLeavesAClosedLeadAlone(): void
    {
        $closed = $this->makeStatus('Closed - Not Sold', open: false);
        $this->moveLeadTo($closed);
        $this->company->set(CustomFieldEnum::ZOHO_GET_DOCS_LEAD_STATUS->value, 'Hot');
        $module = $this->module();
        $module->expects($this->never())->method('update');

        $result = new PushGetDocsLeadStatusAction($this->lead, $module)->execute();

        $this->assertSame('lead_closed', $result['reason']);
        $this->assertSame($closed->getId(), (int) $this->lead->fresh()->leads_status_id);
    }

    public function testASecondSubmissionIsANoOp(): void
    {
        $this->moveLeadTo($this->makeStatus('Hot', open: true));
        $this->company->set(CustomFieldEnum::ZOHO_GET_DOCS_LEAD_STATUS->value, 'Hot');
        $module = $this->module();
        $module->expects($this->never())->method('update');

        $result = new PushGetDocsLeadStatusAction($this->lead, $module)->execute();

        $this->assertSame('already_set', $result['reason']);
    }

    public function testSkipsALeadZohoDoesNotHaveYet(): void
    {
        $lead = $this->makeLead($this->company);
        $this->company->set(CustomFieldEnum::ZOHO_GET_DOCS_LEAD_STATUS->value, 'Hot');
        $module = $this->module();
        $module->expects($this->never())->method('update');

        $result = new PushGetDocsLeadStatusAction($lead, $module)->execute();

        $this->assertSame('lead_not_in_zoho', $result['reason']);
    }

    public function testARejectedZohoWriteLeavesKanvasUntouched(): void
    {
        $this->company->set(CustomFieldEnum::ZOHO_GET_DOCS_LEAD_STATUS->value, 'Hot');
        $module = $this->createStub(ZohoRecordsModule::class);
        $module->method('update')->willThrowException(new RuntimeException('Zoho is down'));
        $before = (int) $this->lead->leads_status_id;

        try {
            new PushGetDocsLeadStatusAction($this->lead, $module)->execute();
            $this->fail('the Zoho failure must surface so the activity retries');
        } catch (RuntimeException) {
        }

        $this->assertSame($before, (int) $this->lead->fresh()->leads_status_id);
    }

    private function acceptingModule(): ZohoRecordsModule
    {
        $module = $this->createStub(ZohoRecordsModule::class);
        $module->method('update')->willReturn(new Record(['id' => 'zl-100'], $module));

        return $module;
    }

    private function makeStatus(string $name, bool $open): LeadStatus
    {
        return LeadStatus::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $this->company->getId(),
            'name' => $name,
            'is_default' => 0,
            'is_open' => $open,
        ]);
    }

    private function moveLeadTo(LeadStatus $status): void
    {
        $this->lead->leads_status_id = $status->getId();
        $this->lead->saveOrFail();
        $this->lead->unsetRelation('status');
    }

    private function companyStatusesNamed(string $name): Builder
    {
        return LeadStatus::query()
            ->where('companies_id', $this->company->getId())
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->notDeleted();
    }
}
