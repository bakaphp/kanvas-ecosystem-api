<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Salesforce\Activities\RequestPeopleApprovalActivity;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Connectors\Salesforce\Enums\PeopleApprovalTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadAttempt;
use Kanvas\Guild\Leads\Models\LeadVariantInterest;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class RequestPeopleApprovalActivityTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm'];

    public function testOpensBothApprovalTypesWithCreateSyncTypeWhenPeopleIsNewToSalesforce(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->seedPeoplePolicies($app, $company);

        $product = Products::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $variant = $product->variants()->first();

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();
        $attempt = $this->attempt(
            $app,
            $company,
            $lead,
            ['variant_id' => $variant->getId()],
        );

        $result = $this->requestApproval(
            $lead,
            $app,
            $company,
            $attempt,
        );

        $this->assertTrue($result[PeopleApprovalTypeEnum::CONTENT->value]['requested']);
        $this->assertTrue($result['sync_salesforce']['requested']);

        $approvePeople = ApprovalRequest::find($result[PeopleApprovalTypeEnum::CONTENT->value]['approval_request_id']);
        $sync = ApprovalRequest::find($result['sync_salesforce']['approval_request_id']);

        $this->assertSame(PeopleApprovalTypeEnum::CONTENT->value, $approvePeople->approval_type);
        $this->assertSame(PeopleApprovalTypeEnum::SALESFORCE_CREATE->value, $sync->approval_type);
        $this->assertNotNull($sync->payload['lead_variant_interest_id']);
        $this->assertArrayNotHasKey('lead_variant_interest_id', $approvePeople->payload);

        $interest = LeadVariantInterest::find($sync->payload['lead_variant_interest_id']);
        $this->assertSame($variant->getId(), $interest->variants_id);
        $this->assertSame($lead->getId(), $interest->leads_id);
    }

    public function testOpensSyncApprovalWithUpdateTypeWhenPeopleAlreadyHasASalesforceContactId(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->seedPeoplePolicies($app, $company);

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();
        $lead->people->set(CustomFieldEnum::SALESFORCE_CONTACT_ID->value, '003xx000004TmiQAAS');
        $attempt = $this->attempt($app, $company, $lead, []);

        $result = $this->requestApproval(
            $lead,
            $app,
            $company,
            $attempt,
        );

        $sync = ApprovalRequest::find($result['sync_salesforce']['approval_request_id']);
        $this->assertSame(PeopleApprovalTypeEnum::SALESFORCE_UPDATE->value, $sync->approval_type);
    }

    public function testSkipsVariantInterestSilentlyWhenVariantIdDoesNotExist(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->seedPeoplePolicies($app, $company);

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();
        $attempt = $this->attempt(
            $app,
            $company,
            $lead,
            ['variant_id' => 999999999],
        );

        $result = $this->requestApproval(
            $lead,
            $app,
            $company,
            $attempt,
        );

        $this->assertTrue($result[PeopleApprovalTypeEnum::CONTENT->value]['requested']);
        $this->assertTrue($result['sync_salesforce']['requested']);
        $this->assertSame(0, LeadVariantInterest::where('leads_id', $lead->getId())->count());

        $sync = ApprovalRequest::find($result['sync_salesforce']['approval_request_id']);
        $this->assertNull($sync->payload['lead_variant_interest_id']);
    }

    public function testOpensBothApprovalsWithoutAVariantInterestWhenPayloadHasNoProperty(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->seedPeoplePolicies($app, $company);

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();
        $attempt = $this->attempt(
            $app,
            $company,
            $lead,
            [
                'title' => 'Arfenis',
                'people' => [
                    'firstname' => 'Arfenis',
                    'lastname' => '',
                    'contacts' => [
                        ['contacts_types_id' => 1, 'value' => 'arfenis@mctekk.com'],
                        ['contacts_types_id' => 2, 'value' => '8292879675'],
                    ],
                ],
                'description' => null,
                'custom_fields' => [
                    'source' => 'contact',
                    'interests' => ['Purchase'],
                ],
            ],
        );

        $result = $this->requestApproval(
            $lead,
            $app,
            $company,
            $attempt,
        );

        $this->assertTrue($result[PeopleApprovalTypeEnum::CONTENT->value]['requested']);
        $this->assertTrue($result['sync_salesforce']['requested']);
        $this->assertSame(0, LeadVariantInterest::where('leads_id', $lead->getId())->count());
    }

    public function testNeitherApprovalTypeReopensWhenBothAreAlreadyPending(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->seedPeoplePolicies($app, $company);

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();
        $attempt = $this->attempt($app, $company, $lead, []);

        $first = $this->requestApproval($lead, $app, $company, $attempt);
        $second = $this->requestApproval($lead, $app, $company, $attempt);

        $this->assertTrue($first[PeopleApprovalTypeEnum::CONTENT->value]['requested']);
        $this->assertTrue($first['sync_salesforce']['requested']);

        $this->assertFalse($second[PeopleApprovalTypeEnum::CONTENT->value]['requested']);
        $this->assertSame('already pending', $second[PeopleApprovalTypeEnum::CONTENT->value]['reason']);
        $this->assertFalse($second['sync_salesforce']['requested']);
        $this->assertSame('already pending', $second['sync_salesforce']['reason']);
    }

    public function testSupersedesTheOlderPendingRequestWhenAutoRejectStalePendingIsEnabled(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->seedPeoplePolicies($app, $company);

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();
        $attempt = $this->attempt($app, $company, $lead, []);
        $first = $this->requestApproval($lead, $app, $company, $attempt);

        $secondLead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->withPeopleId($lead->people->getId())
            ->create();
        $secondAttempt = $this->attempt($app, $company, $secondLead, []);
        $second = $this->requestApproval(
            $secondLead,
            $app,
            $company,
            $secondAttempt,
            ['auto_reject_stale_pending' => true],
        );

        $this->assertTrue($second[PeopleApprovalTypeEnum::CONTENT->value]['requested']);
        $this->assertTrue($second['sync_salesforce']['requested']);
        $this->assertNotSame(
            $first[PeopleApprovalTypeEnum::CONTENT->value]['approval_request_id'],
            $second[PeopleApprovalTypeEnum::CONTENT->value]['approval_request_id'],
        );

        $oldContent = ApprovalRequest::find($first[PeopleApprovalTypeEnum::CONTENT->value]['approval_request_id']);
        $oldSync = ApprovalRequest::find($first['sync_salesforce']['approval_request_id']);
        $newContent = ApprovalRequest::find($second[PeopleApprovalTypeEnum::CONTENT->value]['approval_request_id']);
        $newSync = ApprovalRequest::find($second['sync_salesforce']['approval_request_id']);

        $this->assertSame('rejected', $oldContent->status->value);
        $this->assertSame('Superseded by a more recent approval request', $oldContent->reason);
        $this->assertNull($oldContent->resolved_by_users_id);
        $this->assertSame('rejected', $oldSync->status->value);
        $this->assertSame('Superseded by a more recent approval request', $oldSync->reason);

        $this->assertSame('pending', $newContent->status->value);
        $this->assertSame('pending', $newSync->status->value);
    }

    private function requestApproval(
        Lead $lead,
        Apps $app,
        $company,
        LeadAttempt $attempt,
        array $extraParams = [],
    ): array {
        $activity = new ReflectionClass(RequestPeopleApprovalActivity::class)->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($activity, 'requestApproval');

        return $method->invoke($activity, $lead, $app, $company, ['attempt' => $attempt, ...$extraParams]);
    }

    private function attempt(
        Apps $app,
        $company,
        Lead $lead,
        array $request
    ): LeadAttempt {
        return LeadAttempt::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'leads_id' => $lead->getId(),
            'header' => [],
            'request' => $request,
            'ip' => '127.0.0.1',
            'source' => 'test',
            'public_key' => 'test',
            'processed' => 1,
        ]);
    }

    private function seedPeoplePolicies(Apps $app, $company): void
    {
        $systemModule = SystemModulesRepository::getByModelName(People::class, $app);

        foreach (PeopleApprovalTypeEnum::cases() as $approvalType) {
            ApprovalPolicy::firstOrCreate([
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'system_modules_id' => $systemModule->getId(),
                'approval_type' => $approvalType->value,
            ], [
                'steps' => [['step' => 1, 'resolver' => 'company_owner', 'config' => [], 'required_approvals' => 1]],
                'trigger' => ApprovalTriggerEnum::MANUAL,
                'reject_policy' => 'any',
                'notify' => 'all',
            ]);
        }
    }
}
