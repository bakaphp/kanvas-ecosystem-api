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
            ]
        );
        $lead->set('member', 1);

        $zohoLead = ZohoLead::fromLead($lead);

        $this->assertNotEmpty($zohoLead->First_Name);
        $this->assertNotEmpty($zohoLead->Last_Name);
        $this->assertNotEmpty($zohoLead->additionalFields['Member_ID']);
        $this->assertInstanceOf(ZohoLead::class, $zohoLead);
    }
}
