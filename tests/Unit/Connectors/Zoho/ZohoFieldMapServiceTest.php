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

    public function testInvertTurnsTheInboundDealMapIntoAnOutboundOne(): void
    {
        $inverted = ZohoFieldMapService::invert([
            'Business_Name' => 'business_legal_name',
            'Amount_Requested' => ['name' => 'funding_amount_requested', 'type' => 'double'],
            'Ignored' => '',
            'AlsoIgnored' => ['type' => 'double'],
        ]);

        $this->assertSame([
            'business_legal_name' => 'Business_Name',
            'funding_amount_requested' => ['name' => 'Amount_Requested', 'type' => 'double'],
        ], $inverted);
    }

    public function testTheDefaultDealMapRoundTrips(): void
    {
        $default = ZohoFieldMapService::defaultDealMap();
        $outbound = ZohoFieldMapService::invert($default);

        foreach ($default as $zohoKey => $kanvasKey) {
            $this->assertSame($zohoKey, $outbound[$kanvasKey]);
        }
    }
}
