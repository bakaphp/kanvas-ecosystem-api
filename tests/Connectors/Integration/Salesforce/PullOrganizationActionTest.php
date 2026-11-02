<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Salesforce\Actions\PullOrganizationAction;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Tests\TestCase;

final class PullOrganizationActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'ecosystem'];

    public function testCreatesOrganizationWhenNoMatchingCustomFieldExists(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        $organization = new PullOrganizationAction(
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

        $existing = new PullOrganizationAction(
            $app,
            $company,
            ['Name' => 'Acme Corp'],
            '001xx000003DHP0AAA',
        )->execute();

        $updated = new PullOrganizationAction(
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

        $organization = new PullOrganizationAction(
            $app,
            $company,
            ['Name' => 'Acme Corp'],
            '001xx000003DHP0AAA',
        )->execute();

        // disableWorkflows() was applied before saveOrFail() — fireWorkflow must short-circuit to
        // null instead of dispatching, which is what would re-trigger the outbound Salesforce sync.
        $this->assertNull($organization->fireWorkflow(WorkflowEnum::UPDATED->value));
    }

    public function testStoresDomainsFromDomainFieldsWebsiteAndContactEmails(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();
        $suffix = uniqid();

        $organization = new PullOrganizationAction(
            $app,
            $company,
            [
                'Name' => 'Acme Corp',
                'Company_Domains__c' => "acme-{$suffix}.com, acme-stores-{$suffix}.com",
                'Website' => "https://www.acme-web-{$suffix}.com/home",
                'Contacts' => ['records' => [
                    ['Email' => "jane@acme-mail-{$suffix}.com"],
                    ['Email' => 'personal@gmail.com'],
                ]],
            ],
            '001xx00000DOMAINS' . $suffix,
        )->execute();

        $domains = $organization->emailDomains();
        sort($domains);

        $this->assertSame([
            "acme-{$suffix}.com",
            "acme-mail-{$suffix}.com",
            "acme-stores-{$suffix}.com",
            "acme-web-{$suffix}.com",
        ], $domains);
    }

    public function testRerunAddsDomainsToAnExistingOrganizationWithoutDuplicatingThem(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();
        $suffix = uniqid();
        $salesforceId = '001xx00000RERUN' . $suffix;

        $existing = new PullOrganizationAction(
            $app,
            $company,
            ['Name' => 'Acme Corp'],
            $salesforceId,
        )->execute();
        $this->assertSame([], $existing->emailDomains());

        $payload = [
            'Name' => 'Acme Corp',
            'Company_Domains__c' => "acme-{$suffix}.com; ACME-{$suffix}.com",
            'Website' => "https://www.acme-{$suffix}.com",
            'Contacts' => ['records' => [['Email' => "jane@acme-{$suffix}.com"]]],
        ];

        $first = new PullOrganizationAction(
            $app,
            $company,
            $payload,
            $salesforceId,
        )->execute();
        $second = new PullOrganizationAction(
            $app,
            $company,
            $payload,
            $salesforceId,
        )->execute();

        $this->assertSame($existing->getId(), $first->getId());
        $this->assertSame($existing->getId(), $second->getId());
        $this->assertSame(["acme-{$suffix}.com"], $second->fresh()->emailDomains());
        $this->assertSame(
            1,
            Organization::getByCustomFieldBuilderTransactionSafe(
                CustomFieldEnum::SALESFORCE_ACCOUNT_ID->value,
                $salesforceId,
                $company,
            )->count()
        );
    }
}
