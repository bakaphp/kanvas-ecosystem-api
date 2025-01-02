<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Kanvas\Connectors\Zoho\DataTransferObject\ZohoLead;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

final class ConversionTest extends TestCase
{
    public function testEcosystemLeadToZoho(): void
    {
        //use factory
        $lead = Lead::factory()->create();
        $lead->company->set(
            CustomFieldEnum::FIELDS_MAP->value,
            [
                'member' => [
                    'name' => 'Member_ID',
                    'type' => 'string',
                ],
                'Credit_Score' => [
                    'name' => 'Credit_Score',
                    'type' => 'string',
                ],
            ]
        );
        $lead->set('member', 1);
        $lead->set('Credit_Score', 2);

        $zohoLead = ZohoLead::fromLead($lead);

        $this->assertNotEmpty($zohoLead->First_Name);
        $this->assertNotEmpty($zohoLead->Last_Name);
        $this->assertNotEmpty($zohoLead->additionalFields['Member_ID']);
        $this->assertNotEmpty($zohoLead->additionalFields['Credit_Score']);
        $this->assertInstanceOf(ZohoLead::class, $zohoLead);

        $newArray = $zohoLead->toArray();

        $this->assertArrayHasKey('First_Name', $newArray);
        $this->assertArrayHasKey('Last_Name', $newArray);
        $this->assertArrayHasKey('Member_ID', $newArray);
        $this->assertArrayHasKey('Credit_Score', $newArray);
        $this->assertEquals($newArray['Credit_Score'], '680-719');
    }
}
