<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors\Zoho;

use Kanvas\Connectors\Zoho\Services\ZohoFieldMapService;
use PHPUnit\Framework\TestCase;

final class ZohoFieldMapServiceTest extends TestCase
{
    public function testMapsPlainAndTypedEntriesAndSkipsMissingValues(): void
    {
        $map = [
            'business_legal_name' => 'Business_Name',
            'funding_amount_requested' => ['name' => 'Amount_Requested', 'type' => 'double'],
            'business_start_date' => ['name' => 'Business_Start_Date', 'type' => 'date'],
            'missing_field' => 'Never_Sent',
        ];

        $data = ZohoFieldMapService::map($map, [
            'business_legal_name' => 'Acme LLC',
            'funding_amount_requested' => '$50,000',
            'business_start_date' => '08/28/2020',
        ]);

        $this->assertSame('Acme LLC', $data['Business_Name']);
        $this->assertSame(50000.0, $data['Amount_Requested']);
        $this->assertSame('2020-08-28', $data['Business_Start_Date']);
        $this->assertArrayNotHasKey('Never_Sent', $data);
    }

    public function testADateThatCannotBeTrustedIsDroppedRatherThanSentAsToday(): void
    {
        $data = ZohoFieldMapService::map(
            ['business_start_date' => ['name' => 'Business_Start_Date', 'type' => 'date']],
            ['business_start_date' => 'soon']
        );

        $this->assertSame([], $data);
    }

    public function testCreditScoreBucketsAreTranslatedForZoho(): void
    {
        $data = ZohoFieldMapService::map(['credit_score' => 'Credit_Score'], ['credit_score' => 2]);

        $this->assertSame('680-719', $data['Credit_Score']);
    }

    public function testExtractReadsTheSameMapInThePullDirection(): void
    {
        $map = [
            'zip' => ['name' => 'Zip_Code', 'type' => 'string'],
            'business_founded' => ['name' => 'Business_Founded', 'type' => 'date'],
            'Rating' => ['name' => 'Rating', 'type' => 'string'],
            'Credit_Score' => 'Credit_Score',
            'title' => 'Deal_Name',
            'agent_notes' => 'Agent_Notes',
        ];

        $fields = ZohoFieldMapService::extract($map, [
            'Zip_Code' => '33033',
            'Business_Founded' => '08/28/2020',
            'Rating' => 'Hot',
            'Credit_Score' => '720-950',
            'Deal_Name' => 'Acme',
            'Agent_Notes' => '',
        ], ['title']);

        $this->assertSame([
            'zip' => '33033',
            'business_founded' => '2020-08-28',
            'Rating' => 'Hot',
            'Credit_Score' => '720-950',
        ], $fields);
    }

    public function testAnEntryWithoutANameMeansTheKanvasKeyIsTheZohoName(): void
    {
        $this->assertSame('Rating', ZohoFieldMapService::zohoFieldName(['type' => 'string'], 'Rating'));
        $this->assertSame('Zip_Code', ZohoFieldMapService::zohoFieldName(['name' => 'Zip_Code'], 'zip'));
        $this->assertSame('Street', ZohoFieldMapService::zohoFieldName('Street', 'street'));
    }

    public function testTheDefaultDealMapIsKanvasFirstLikeTheLeadMap(): void
    {
        $default = ZohoFieldMapService::defaultDealMap();

        $this->assertSame('Zip_Code', $default['business_zip']);
        $this->assertSame('Amount_Requested', $default['funding_amount_requested']);
        $this->assertSame(['business_zip' => '33033'], ZohoFieldMapService::extract($default, ['Zip_Code' => '33033']));
    }
}
