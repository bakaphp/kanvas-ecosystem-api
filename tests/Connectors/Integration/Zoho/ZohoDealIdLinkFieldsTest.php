<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Connectors\Zoho\Services\ZohoFieldMapService;
use Tests\Connectors\Integration\Zoho\Concerns\BuildsZohoDeal;
use Tests\Connectors\Integration\Zoho\Support\StubbedPushDealToZohoAction;
use Tests\TestCase;
use Webleit\ZohoCrmApi\Models\Record;

/**
 * The deal twin of ZohoLeadIdLinkFieldsTest: URL fields embedding the Zoho deal id are a second write
 * after the create and part of the payload on updates.
 */
final class ZohoDealIdLinkFieldsTest extends TestCase
{
    use BuildsZohoDeal;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'social', 'ecosystem'];

    private const array TEMPLATES = [
        'Stand_Alone_Deals_Doc_Upload' => 'https://apply2.example.com/upload?deal_id={zoho_deal_id}',
        'Form_URL' => 'https://apply2.example.com/apply?deal_id={zoho_deal_id}&k={deal_id}',
    ];

    public function testTheCreateIsFollowedByOneUpdateCarryingTheResolvedLinks(): void
    {
        $company = $this->makeZohoCompany();
        $company->set(CustomFieldEnum::ZOHO_DEAL_ID_LINK_FIELDS->value, self::TEMPLATES);
        $deal = $this->makeDeal($company);

        $module = $this->module();
        $module->expects($this->once())
            ->method('create')
            ->willReturn(new Record(['id' => 'zd-500'], $module));
        $module->expects($this->once())
            ->method('update')
            ->with('zd-500', [
                'Stand_Alone_Deals_Doc_Upload' => 'https://apply2.example.com/upload?deal_id=zd-500',
                'Form_URL' => 'https://apply2.example.com/apply?deal_id=zd-500&k=' . $deal->getId(),
            ])
            ->willReturn(new Record(['id' => 'zd-500'], $module));

        $result = new StubbedPushDealToZohoAction($deal, $module)->execute();

        $this->assertSame('zd-500', $result['zohoDealId']);
    }

    public function testAFailedCreateWritesNoLinks(): void
    {
        $company = $this->makeZohoCompany();
        $company->set(CustomFieldEnum::ZOHO_DEAL_ID_LINK_FIELDS->value, self::TEMPLATES);
        $deal = $this->makeDeal($company);

        $module = $this->module();
        $module->expects($this->once())->method('create')->willReturn(null);
        $module->expects($this->never())->method('update');

        $this->assertNull(new StubbedPushDealToZohoAction($deal, $module)->execute()['zohoDealId']);
    }

    public function testAnUpdateCarriesTheLinksInTheSamePayload(): void
    {
        $company = $this->makeZohoCompany();
        $company->set(CustomFieldEnum::ZOHO_DEAL_ID_LINK_FIELDS->value, self::TEMPLATES);
        $deal = $this->makeDeal($company);
        $deal->set(CustomFieldEnum::ZOHO_DEAL_ID->value, 'zd-7');

        $module = $this->module();
        $module->method('get')->with('zd-7')->willReturn(new Record(['id' => 'zd-7'], $module));
        $module->expects($this->never())->method('create');
        $module->expects($this->once())
            ->method('update')
            ->with('zd-7', $this->callback(
                fn (array $data) => $data['Stand_Alone_Deals_Doc_Upload'] === 'https://apply2.example.com/upload?deal_id=zd-7'
                    && $data['Deal_Name'] === $deal->title
            ))
            ->willReturn(new Record(['id' => 'zd-7'], $module));

        new StubbedPushDealToZohoAction($deal, $module)->execute();
    }

    public function testTheDealMapIsSeparateFromTheLeadMap(): void
    {
        $company = $this->makeZohoCompany();
        $company->set(CustomFieldEnum::ZOHO_LEAD_ID_LINK_FIELDS->value, self::TEMPLATES);
        $deal = $this->makeDeal($company);

        $this->assertSame([], ZohoFieldMapService::dealIdLinkFields($deal, 'zd-9'));
    }
}
