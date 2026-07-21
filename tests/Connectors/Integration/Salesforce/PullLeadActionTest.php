<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Salesforce\Actions\PullDealAction;
use Kanvas\Connectors\Salesforce\Actions\PullLeadAction;
use Kanvas\Connectors\Salesforce\Actions\PullOrganizationAction;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Tests\TestCase;

final class PullLeadActionTest extends TestCase
{
    use DatabaseTransactions;

    public function testCreatesLeadWhenNoMatchingCustomFieldExists(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        $lead = new PullLeadAction(
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

        $existing = new PullLeadAction(
            $app,
            $company,
            ['FirstName' => 'Jane', 'LastName' => 'Doe'],
            '00Qxx0000004C92AAE',
        )->execute();

        $updated = new PullLeadAction(
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

        $lead = new PullLeadAction(
            $app,
            $company,
            ['FirstName' => 'Jane', 'LastName' => 'Doe'],
            '00Qxx0000004C92AAE',
        )->execute();

        // SyncLeadByThirdPartyCustomFieldAction always disables workflows before saving (update
        // path) and CreateLeadAction disables them on create because runWorkflow:false was passed
        // — either way, fireWorkflow must short-circuit to null.
        $this->assertNull($lead->fireWorkflow(WorkflowEnum::CREATED->value));

        $updated = new PullLeadAction(
            $app,
            $company,
            ['FirstName' => 'Jane', 'LastName' => 'Doe Updated'],
            '00Qxx0000004C92AAE',
        )->execute();

        $this->assertNull($updated->fireWorkflow(WorkflowEnum::UPDATED->value));
    }

    public function testLinksConvertedOrganizationWhenLeadIsConverted(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        $organization = new PullOrganizationAction(
            $app,
            $company,
            ['Name' => 'Acme Corp'],
            '001xx000003DHP0AAA',
        )->execute();

        $lead = new PullLeadAction(
            $app,
            $company,
            [
                'FirstName' => 'Jane',
                'LastName' => 'Doe',
                'IsConverted' => 'true',
                'ConvertedAccountId' => '001xx000003DHP0AAA',
            ],
            '00Qxx0000004C92AAE',
        )->execute();

        $this->assertSame($organization->getId(), $lead->organization_id);
    }

    public function testDoesNotLinkOrganizationWhenLeadIsNotConverted(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        new PullOrganizationAction(
            $app,
            $company,
            ['Name' => 'Acme Corp Unconverted'],
            '001xx000003DHP0BBB',
        )->execute();

        $lead = new PullLeadAction(
            $app,
            $company,
            [
                'FirstName' => 'John',
                'LastName' => 'Smith',
                'ConvertedAccountId' => '001xx000003DHP0BBB',
            ],
            '00Qxx0000004C92BBB',
        )->execute();

        $this->assertNull($lead->organization_id);
    }

    public function testLinksConvertedContactWhenLeadIsConverted(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        $lead = new PullLeadAction(
            $app,
            $company,
            [
                'FirstName' => 'Jane',
                'LastName' => 'Doe',
                'IsConverted' => 'true',
                'ConvertedContactId' => '003xx000004TmiQCCC',
            ],
            '00Qxx0000004C92CCC',
        )->execute();

        $this->assertSame(
            '003xx000004TmiQCCC',
            $lead->people->get(CustomFieldEnum::SALESFORCE_CONTACT_ID->value),
        );
    }

    public function testLinksConvertedOpportunityWhenLeadIsConverted(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        $deal = new PullDealAction(
            $app,
            $company,
            ['Name' => 'Converted Opportunity'],
            '006xx000004TmXtDDD',
        )->execute();

        $lead = new PullLeadAction(
            $app,
            $company,
            [
                'FirstName' => 'Jane',
                'LastName' => 'Doe',
                'IsConverted' => 'true',
                'ConvertedOpportunityId' => '006xx000004TmXtDDD',
            ],
            '00Qxx0000004C92DDD',
        )->execute();

        $this->assertSame($lead->getId(), $deal->refresh()->leads_id);
    }
}
