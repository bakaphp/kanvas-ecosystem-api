<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Salesforce\Actions\SyncSalesforceLeadAction;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Tests\TestCase;

final class SyncSalesforceLeadActionTest extends TestCase
{
    use DatabaseTransactions;

    public function testCreatesLeadWhenNoMatchingCustomFieldExists(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        $lead = new SyncSalesforceLeadAction(
            $app,
            $company,
            [
                'FirstName' => 'Jane',
                'LastName' => 'Doe',
                'Email' => 'jane.doe@example.com',
                'Phone' => '555-9876',
                'Status' => 'Open',
            ],
            '00Qxx0000004C92AAE',
        )->execute();

        $this->assertSame('Jane', $lead->firstname);
        $this->assertSame('Doe', $lead->lastname);
        $this->assertSame('00Qxx0000004C92AAE', $lead->get(CustomFieldEnum::SALESFORCE_LEAD_ID->value));
    }

    public function testUpdatesLeadWhenMatchingCustomFieldExists(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        $existing = new SyncSalesforceLeadAction(
            $app,
            $company,
            ['FirstName' => 'Jane', 'LastName' => 'Doe'],
            '00Qxx0000004C92AAE',
        )->execute();

        $updated = new SyncSalesforceLeadAction(
            $app,
            $company,
            ['FirstName' => 'Jane', 'LastName' => 'Smith', 'Description' => 'Updated from Salesforce'],
            '00Qxx0000004C92AAE',
        )->execute();

        $this->assertSame($existing->getId(), $updated->getId());
        $this->assertSame('Smith', $updated->lastname);
        $this->assertSame('Updated from Salesforce', $updated->description);
    }

    public function testInboundWriteNeverFiresOutboundWorkflow(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        $lead = new SyncSalesforceLeadAction(
            $app,
            $company,
            ['FirstName' => 'Jane', 'LastName' => 'Doe'],
            '00Qxx0000004C92AAE',
        )->execute();

        // SyncLeadByThirdPartyCustomFieldAction always disables workflows before saving (update
        // path) and CreateLeadAction disables them on create because runWorkflow:false was passed
        // — either way, fireWorkflow must short-circuit to null.
        $this->assertNull($lead->fireWorkflow(WorkflowEnum::CREATED->value));

        $updated = new SyncSalesforceLeadAction(
            $app,
            $company,
            ['FirstName' => 'Jane', 'LastName' => 'Doe Updated'],
            '00Qxx0000004C92AAE',
        )->execute();

        $this->assertNull($updated->fireWorkflow(WorkflowEnum::UPDATED->value));
    }
}
