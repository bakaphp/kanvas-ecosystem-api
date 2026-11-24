<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Connectors\Zoho\Services\ZohoFieldMapService;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\Connectors\Integration\Zoho\Concerns\BuildsZohoDeal;
use Tests\Connectors\Integration\Zoho\Concerns\StubsZohoLeadModule;
use Tests\TestCase;
use Webleit\ZohoCrmApi\Models\Record;

/**
 * A tenant can keep Zoho URL fields that embed the Zoho lead id itself. That id only exists once the
 * create returns, so the links are a second write after the create and part of the payload on updates.
 */
final class ZohoLeadIdLinkFieldsTest extends TestCase
{
    use BuildsZohoDeal;
    use DatabaseTransactions;
    use StubsZohoLeadModule;

    protected $connectionsToTransact = [null, 'crm', 'social', 'ecosystem'];

    private const array TEMPLATES = [
        'Stand_Alone_Leads_Doc_Upload' => 'https://apply2.example.com/upload?lead_id={zoho_lead_id}',
        'Form_URL' => 'https://apply2.example.com/apply?lead_id={zoho_lead_id}&k={lead_id}',
    ];

    public function testTheCreateIsFollowedByOneUpdateCarryingTheResolvedLinks(): void
    {
        $company = $this->makeZohoCompany();
        $company->set(CustomFieldEnum::ZOHO_LEAD_ID_LINK_FIELDS->value, self::TEMPLATES);
        $lead = $this->makeLead($company);

        $module = $this->module();
        $module->expects($this->once())
            ->method('create')
            ->willReturn(new Record(['id' => '2896936000576318046'], $module));
        $module->expects($this->once())
            ->method('update')
            ->with('2896936000576318046', [
                'Stand_Alone_Leads_Doc_Upload' => 'https://apply2.example.com/upload?lead_id=2896936000576318046',
                'Form_URL' => 'https://apply2.example.com/apply?lead_id=2896936000576318046&k=' . $lead->getId(),
            ])
            ->willReturn(new Record(['id' => '2896936000576318046'], $module));

        $this->action($lead, $module)->execute();

        $this->assertSame('2896936000576318046', $lead->get(CustomFieldEnum::ZOHO_LEAD_ID->value));
    }

    public function testACompanyWithoutTheMapGetsNoSecondWrite(): void
    {
        $company = $this->makeZohoCompany();
        $lead = $this->makeLead($company);

        $module = $this->module();
        $module->expects($this->once())
            ->method('create')
            ->willReturn(new Record(['id' => 'zl-1'], $module));
        $module->expects($this->never())->method('update');

        $this->action($lead, $module)->execute();
    }

    public function testAnUpdateCarriesTheLinksInTheSamePayload(): void
    {
        $company = $this->makeZohoCompany();
        $company->set(CustomFieldEnum::ZOHO_LEAD_ID_LINK_FIELDS->value, self::TEMPLATES);
        $lead = $this->makeLead($company);
        $lead->set(CustomFieldEnum::ZOHO_LEAD_ID->value, 'zl-77');

        $module = $this->module();
        $module->method('get')->with('zl-77')->willReturn(new Record(['id' => 'zl-77'], $module));
        $module->expects($this->never())->method('create');
        $module->expects($this->once())
            ->method('update')
            ->with('zl-77', $this->callback(
                fn (array $data) => $data['Stand_Alone_Leads_Doc_Upload'] === 'https://apply2.example.com/upload?lead_id=zl-77'
                    && isset($data['First_Name'])
            ))
            ->willReturn(new Record(['id' => 'zl-77'], $module));

        $this->action($lead, $module)->execute();
    }

    public function testMalformedEntriesAreDroppedAndAMissingIdResolvesNothing(): void
    {
        $company = $this->makeZohoCompany();
        $company->set(CustomFieldEnum::ZOHO_LEAD_ID_LINK_FIELDS->value, [
            'Form_URL' => 'https://apply2.example.com/{lead_uuid}',
            'Broken' => ['not' => 'a template'],
            0 => 'https://apply2.example.com/{zoho_lead_id}',
        ]);
        $lead = $this->makeLead($company);

        $this->assertSame(
            ['Form_URL' => 'https://apply2.example.com/' . $lead->uuid],
            ZohoFieldMapService::leadIdLinkFields($lead, 'zl-9')
        );
        $this->assertSame([], ZohoFieldMapService::leadIdLinkFields($lead, null));
    }
}
