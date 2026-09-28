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
use Kanvas\Connectors\Salesforce\Enums\PeopleSalesforceSyncApprovalTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadAttempt;
use Kanvas\Guild\Leads\Models\LeadVariantInterest;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Covers only the Salesforce sync gate. The plain content-review approval
 * (Guild\Customers\Activities\RequestPeopleContentApprovalActivity) has its own test — the two Activities
 * are independent, so this one no longer touches `approve_people`.
 */
final class RequestPeopleApprovalActivityTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm'];

    public function testOpensCreateSyncTypeWhenPeopleIsNewToSalesforce(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->seedSyncPolicies($app, $company);

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

        $this->assertTrue($result['requested']);

        $sync = ApprovalRequest::find($result['approval_request_id']);
        $this->assertSame(PeopleSalesforceSyncApprovalTypeEnum::CREATE->value, $sync->approval_type);
        $this->assertNotNull($sync->payload['lead_variant_interest_id']);

        $interest = LeadVariantInterest::find($sync->payload['lead_variant_interest_id']);
        $this->assertSame($variant->getId(), $interest->variants_id);
        $this->assertSame($lead->getId(), $interest->leads_id);
    }

    public function testOpensUpdateSyncTypeWhenPeopleAlreadyHasASalesforceContactId(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->seedSyncPolicies($app, $company);

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();
        $lead->people->set(CustomFieldEnum::SALESFORCE_CONTACT_ID->value, '003xx000004TmiQAAS');
        $attempt = $this->attempt(
            $app,
            $company,
            $lead,
            [],
        );

        $result = $this->requestApproval(
            $lead,
            $app,
            $company,
            $attempt,
        );

        $sync = ApprovalRequest::find($result['approval_request_id']);
        $this->assertSame(PeopleSalesforceSyncApprovalTypeEnum::UPDATE->value, $sync->approval_type);
    }

    public function testSkipsVariantInterestSilentlyWhenVariantIdDoesNotExist(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->seedSyncPolicies($app, $company);

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

        $this->assertTrue($result['requested']);
        $this->assertSame(0, LeadVariantInterest::where('leads_id', $lead->getId())->count());

        $sync = ApprovalRequest::find($result['approval_request_id']);
        $this->assertNull($sync->payload['lead_variant_interest_id']);
    }

    public function testOpensSyncApprovalWithoutAVariantInterestWhenPayloadHasNoProperty(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->seedSyncPolicies($app, $company);

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

        $this->assertTrue($result['requested']);
        $this->assertSame(0, LeadVariantInterest::where('leads_id', $lead->getId())->count());
    }

    public function testDoesNotReopenWhenAlreadyPending(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->seedSyncPolicies($app, $company);

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();
        $attempt = $this->attempt(
            $app,
            $company,
            $lead,
            [],
        );

        $first = $this->requestApproval(
            $lead,
            $app,
            $company,
            $attempt,
        );
        $second = $this->requestApproval(
            $lead,
            $app,
            $company,
            $attempt,
        );

        $this->assertTrue($first['requested']);
        $this->assertFalse($second['requested']);
        $this->assertSame('already pending', $second['reason']);
    }

    public function testSupersedesTheOlderPendingRequestWhenAutoRejectStalePendingIsEnabled(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->seedSyncPolicies($app, $company);

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();
        $attempt = $this->attempt(
            $app,
            $company,
            $lead,
            [],
        );
        $first = $this->requestApproval(
            $lead,
            $app,
            $company,
            $attempt,
        );

        $secondLead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->withPeopleId($lead->people->getId())
            ->create();
        $secondAttempt = $this->attempt(
            $app,
            $company,
            $secondLead,
            [],
        );
        $second = $this->requestApproval(
            $secondLead,
            $app,
            $company,
            $secondAttempt,
            ['auto_reject_stale_pending' => true],
        );

        $this->assertTrue($second['requested']);
        $this->assertNotSame($first['approval_request_id'], $second['approval_request_id']);

        $old = ApprovalRequest::find($first['approval_request_id']);
        $new = ApprovalRequest::find($second['approval_request_id']);

        $this->assertSame('rejected', $old->status->value);
        $this->assertSame('Superseded by a more recent approval request', $old->reason);
        $this->assertNull($old->resolved_by_users_id);
        $this->assertSame('pending', $new->status->value);
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

    private function seedSyncPolicies(Apps $app, $company): void
    {
        $systemModule = SystemModulesRepository::getByModelName(People::class, $app);

        foreach (PeopleSalesforceSyncApprovalTypeEnum::cases() as $approvalType) {
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
