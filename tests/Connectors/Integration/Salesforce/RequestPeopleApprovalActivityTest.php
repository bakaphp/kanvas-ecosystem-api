<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Salesforce\Activities\RequestPeopleApprovalActivity;
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

    public function testOpensApprovalAndCreatesVariantInterestWhenPayloadHasAVariantId(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->seedApprovePeoplePolicy($app, $company);

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
        $this->assertNotNull($result['lead_variant_interest_id']);
        $this->assertNotNull($lead->people->pendingApproval());

        $interest = LeadVariantInterest::find($result['lead_variant_interest_id']);
        $this->assertSame($variant->getId(), $interest->variants_id);
        $this->assertSame($lead->getId(), $interest->leads_id);
    }

    public function testSkipsSilentlyWhenVariantIdDoesNotExist(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->seedApprovePeoplePolicy($app, $company);

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
        $this->assertNull($result['lead_variant_interest_id']);
        $this->assertSame(0, LeadVariantInterest::where('leads_id', $lead->getId())->count());
    }

    public function testOpensApprovalWithoutAVariantInterestWhenPayloadHasNoProperty(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->seedApprovePeoplePolicy($app, $company);

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
        $this->assertNull($result['lead_variant_interest_id']);
        $this->assertSame(0, LeadVariantInterest::where('leads_id', $lead->getId())->count());
    }

    public function testDoesNotOpenASecondApprovalWhenOneIsAlreadyPending(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->seedApprovePeoplePolicy($app, $company);

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

    private function requestApproval(
        Lead $lead,
        Apps $app,
        $company,
        LeadAttempt $attempt
    ): array {
        $activity = new ReflectionClass(RequestPeopleApprovalActivity::class)->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($activity, 'requestApproval');

        return $method->invoke($activity, $lead, $app, $company, ['attempt' => $attempt]);
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

    private function seedApprovePeoplePolicy(Apps $app, $company): void
    {
        ApprovalPolicy::firstOrCreate([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'system_modules_id' => SystemModulesRepository::getByModelName(People::class, $app)->getId(),
            'approval_type' => 'approve_people',
        ], [
            'steps' => [['step' => 1, 'resolver' => 'company_owner', 'config' => [], 'required_approvals' => 1]],
            'trigger' => ApprovalTriggerEnum::MANUAL,
            'reject_policy' => 'any',
            'notify' => 'all',
        ]);
    }
}
