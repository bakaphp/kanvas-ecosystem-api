<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Salesforce\Actions\SyncSalesforceAccountAction;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Tests\TestCase;

final class SyncSalesforceAccountActionTest extends TestCase
{
    use DatabaseTransactions;

    public function testCreatesOrganizationWhenNoMatchingCustomFieldExists(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        $organization = new SyncSalesforceAccountAction(
            $app,
            $company,
            ['Name' => 'Acme Corp', 'Phone' => '555-1234', 'NumberOfEmployees' => 42],
            '001xx000003DHP0AAA',
        )->execute();

        $this->assertSame('Acme Corp', $organization->name);
        $this->assertSame(42, $organization->total_employees);
        $this->assertSame('001xx000003DHP0AAA', $organization->get(CustomFieldEnum::SALESFORCE_ACCOUNT_ID->value));
    }

    public function testUpdatesOrganizationWhenMatchingCustomFieldExists(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        $existing = new SyncSalesforceAccountAction(
            $app,
            $company,
            ['Name' => 'Acme Corp'],
            '001xx000003DHP0AAA',
        )->execute();

        $updated = new SyncSalesforceAccountAction(
            $app,
            $company,
            ['Name' => 'Acme Corp Renamed', 'NumberOfEmployees' => 99],
            '001xx000003DHP0AAA',
        )->execute();

        $this->assertSame($existing->getId(), $updated->getId());
        $this->assertSame('Acme Corp Renamed', $updated->name);
        $this->assertSame(99, $updated->total_employees);
    }

    public function testInboundWriteNeverFiresOutboundWorkflow(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        $organization = new SyncSalesforceAccountAction(
            $app,
            $company,
            ['Name' => 'Acme Corp'],
            '001xx000003DHP0AAA',
        )->execute();

        // disableWorkflows() was applied before saveOrFail() — fireWorkflow must short-circuit to
        // null instead of dispatching, which is what would re-trigger the outbound Salesforce sync.
        $this->assertNull($organization->fireWorkflow(WorkflowEnum::UPDATED->value));
    }
}
