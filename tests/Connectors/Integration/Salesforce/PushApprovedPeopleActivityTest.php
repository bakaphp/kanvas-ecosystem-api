<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Connectors\Salesforce\Activities\PushApprovedPeopleActivity;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadVariantInterest;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use ReflectionClass;
use ReflectionMethod;
use Tests\Connectors\Traits\HasSalesforceConfiguration;
use Tests\TestCase;

final class PushApprovedPeopleActivityTest extends TestCase
{
    use DatabaseTransactions;
    use HasSalesforceConfiguration;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'inventory'];

    public function testPushesPeopleAndPropertyInterestWhenApprovalCarriesAVariantInterest(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->configureSalesforce($company);

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();

        $product = Products::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $product->set(CustomFieldEnum::SALESFORCE_LOCATION_ID->value, 'a006g00000Gk2NiAAJ');
        $variant = $product->variants()->first();

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->withPeopleId($people->getId())
            ->create();

        $interest = LeadVariantInterest::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'leads_id' => $lead->getId(),
            'variants_id' => $variant->getId(),
            'is_active' => true,
        ]);

        $approvalRequest = $this->approvalRequest(
            $app,
            $company,
            $people,
            ['lead_variant_interest_id' => $interest->getId()],
        );

        $this->fakeSalesforceOAuth();
        Http::fake([
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/query*' => Http::response(['records' => []], 200),
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Contact' => Http::response(['id' => '003xx000004TmiQAAS'], 201),
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Location_Contact__c' => Http::response(['id' => 'a01xx000003NEW1AAO'], 201),
        ]);

        $this->push($people, $approvalRequest);

        Http::assertSent(fn ($request) => $request->url() === self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Contact'
            && $request->method() === 'POST');
        Http::assertSent(fn ($request) => $request->url() === self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Location_Contact__c'
            && $request->method() === 'POST');
    }

    public function testPushesOnlyPeopleWhenApprovalCarriesNoVariantInterest(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->configureSalesforce($company);

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();

        $approvalRequest = $this->approvalRequest(
            $app,
            $company,
            $people,
            [],
        );

        $this->fakeSalesforceOAuth();
        Http::fake([
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/query*' => Http::response(['records' => []], 200),
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Contact' => Http::response(['id' => '003xx000004TmiQAAS'], 201),
        ]);

        $this->push($people, $approvalRequest);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/sobjects/Location_Contact__c') && $request->method() === 'POST');
    }

    private function push(People $people, ApprovalRequest $approvalRequest): array
    {
        $activity = new ReflectionClass(PushApprovedPeopleActivity::class)->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($activity, 'push');

        return $method->invoke($activity, $people, $approvalRequest);
    }

    private function approvalRequest(
        Apps $app,
        $company,
        People $people,
        array $payload
    ): ApprovalRequest {
        $systemModule = SystemModulesRepository::getByModelName(People::class, $app);

        return ApprovalRequest::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'system_modules_id' => $systemModule->getId(),
            'entity_id' => $people->getId(),
            'approval_type' => 'approve_people',
            'origin' => ApprovalOriginEnum::SYSTEM,
            'payload' => $payload,
        ]);
    }
}
