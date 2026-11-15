<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Tests\Connectors\Integration\Zoho\Concerns\BuildsZohoDeal;
use Tests\Connectors\Integration\Zoho\Concerns\StubsZohoLeadModule;
use Tests\Connectors\Integration\Zoho\Support\StubbedPushDealToZohoAction;
use Tests\TestCase;
use Webleit\ZohoCrmApi\Exception\ApiError;
use Webleit\ZohoCrmApi\Models\Record;

final class ZohoPushesOnlyChangedFieldsTest extends TestCase
{
    use BuildsZohoDeal;
    use DatabaseTransactions;
    use StubsZohoLeadModule;

    protected $connectionsToTransact = [null, 'crm', 'social', 'ecosystem'];

    public function testAnUnchangedLeadMakesNoUpdateCall(): void
    {
        $lead = $this->makeLead($this->makeZohoCompany());
        $lead->description = 'First note';
        $lead->saveOrFail();

        $module = $this->module();
        $module->method('create')->willReturn(new Record(['id' => 'zl-1'], $module));
        $module->method('get')->willReturn(new Record(['id' => 'zl-1'], $module));
        $module->expects($this->never())->method('update');

        $this->action($lead, $module)->execute();
        $sent = $this->action($lead, $module)->execute();

        $this->assertSame([], $sent);
    }

    public function testALeadUpdateSendsOnlyTheFieldThatChanged(): void
    {
        $lead = $this->makeLead($this->makeZohoCompany());
        $lead->description = 'First note';
        $lead->saveOrFail();

        $module = $this->module();
        $module->method('create')->willReturn(new Record(['id' => 'zl-2'], $module));
        $module->method('get')->willReturn(new Record(['id' => 'zl-2'], $module));
        $module->expects($this->once())
            ->method('update')
            ->with('zl-2', ['Description' => 'Second note'])
            ->willReturn(new Record(['id' => 'zl-2'], $module));

        $this->action($lead, $module)->execute();

        $lead->description = 'Second note';
        $lead->saveOrFail();

        $this->action($lead, $module)->execute();
        $this->action($lead, $module)->execute();
    }

    public function testHashesKeptForAnotherZohoRecordDoNotSuppressTheUpdate(): void
    {
        $lead = $this->makeLead($this->makeZohoCompany());
        $lead->description = 'First note';
        $lead->saveOrFail();

        $module = $this->module();
        $module->method('create')->willReturn(new Record(['id' => 'zl-3'], $module));
        $module->method('get')->willReturn(new Record(['id' => 'zl-4'], $module));

        $this->action($lead, $module)->execute();
        $lead->set(CustomFieldEnum::ZOHO_LEAD_ID->value, 'zl-4');

        $module->expects($this->once())
            ->method('update')
            ->with('zl-4', $this->callback(
                fn (array $data) => $data['Description'] === 'First note' && isset($data['First_Name'])
            ))
            ->willReturn(new Record(['id' => 'zl-4'], $module));

        $this->action($lead, $module)->execute();
    }

    public function testAnUnchangedDealMakesNoUpdateCallAndAChangedOneSendsOnlyThatField(): void
    {
        $deal = $this->makeDeal($this->makeZohoCompany());

        $module = $this->module();
        $module->method('create')->willReturn(new Record(['id' => 'zd-1'], $module));
        $module->method('get')->willReturn(new Record(['id' => 'zd-1'], $module));
        $module->expects($this->once())
            ->method('update')
            ->with('zd-1', ['Deal_Name' => 'Renamed deal'])
            ->willReturn(new Record(['id' => 'zd-1'], $module));

        new StubbedPushDealToZohoAction($deal, $module)->execute();
        $unchanged = new StubbedPushDealToZohoAction($deal, $module)->execute();
        $this->assertSame([], $unchanged['zohoRequest']);

        $deal->title = 'Renamed deal';
        $deal->saveOrFail();

        $changed = new StubbedPushDealToZohoAction($deal, $module)->execute();
        $this->assertSame(['Deal_Name' => 'Renamed deal'], $changed['zohoRequest']);
    }

    public function testACompanyCanKeepTheStageOutOfDealUpdates(): void
    {
        $company = $this->makeZohoCompany();
        $company->set(CustomFieldEnum::ZOHO_DEAL_SKIP_STAGE_ON_UPDATE->value, true);
        $deal = $this->makeDeal($company, stage: $this->makeStage($company, 'Landing'));

        $module = $this->module();
        $module->expects($this->once())
            ->method('create')
            ->with($this->callback(fn (array $data) => $data['Stage'] === 'Landing'))
            ->willReturn(new Record(['id' => 'zd-3'], $module));
        $module->method('get')->willReturn(new Record(['id' => 'zd-3'], $module));
        $module->expects($this->never())->method('update');

        new StubbedPushDealToZohoAction($deal, $module)->execute();

        $deal->pipeline_stage_id = $this->makeStage($company, 'Funded')->getId();
        $deal->saveOrFail();

        $moved = new StubbedPushDealToZohoAction($deal, $module)->execute();
        $this->assertSame([], $moved['zohoRequest']);
    }

    public function testACoercedValueIsNotResentOnTheNextPush(): void
    {
        $deal = $this->makeDeal($this->makeZohoCompany());
        $deal->set(CustomFieldEnum::ZOHO_DEAL_ID->value, 'zd-2');
        $deal->set('funding_amount_requested', '$50,000');

        $rejection = new ApiError(new Response(400, [], json_encode([
            'data' => [[
                'code' => 'INVALID_DATA',
                'details' => ['api_name' => 'Amount_Requested', 'expected_data_type' => 'currency'],
            ]],
        ])));

        $module = $this->module();
        $module->method('get')->willReturn(new Record(['id' => 'zd-2'], $module));
        $calls = 0;
        $module->expects($this->exactly(2))
            ->method('update')
            ->willReturnCallback(function () use (&$calls, $rejection, $module) {
                if (++$calls === 1) {
                    throw $rejection;
                }

                return new Record(['id' => 'zd-2'], $module);
            });

        $first = new StubbedPushDealToZohoAction($deal, $module)->execute();
        $this->assertSame(50000.0, $first['zohoRequest']['Amount_Requested']);

        $second = new StubbedPushDealToZohoAction($deal, $module)->execute();
        $this->assertSame([], $second['zohoRequest']);
    }
}
