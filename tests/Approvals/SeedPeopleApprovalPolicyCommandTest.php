<?php

declare(strict_types=1);

namespace Tests\Approvals;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Salesforce\Enums\PeopleSalesforceSyncApprovalTypeEnum;
use Kanvas\Guild\Customers\Enums\PeopleApprovalTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Tests\TestCase;

final class SeedPeopleApprovalPolicyCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem'];

    private const string COMMAND = 'kanvas:approvals:seed-people-policy';

    public function testCreatesAllThreePeoplePolicies(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        $this->artisan(self::COMMAND, [
            'apps_id' => $app->getId(),
            'company_id' => $company->getId(),
        ])->assertSuccessful();

        $approvalTypes = [
            ...array_column(PeopleApprovalTypeEnum::cases(), 'value'),
            ...array_column(PeopleSalesforceSyncApprovalTypeEnum::cases(), 'value'),
        ];

        foreach ($approvalTypes as $approvalType) {
            $policy = $this->policy($app, $company, $approvalType);

            $this->assertSame($approvalType, $policy->approval_type);
            $this->assertSame(ApprovalTriggerEnum::MANUAL, $policy->trigger);
            $this->assertNull($policy->handler);
        }
    }

    public function testRunningItTwiceLeavesExistingPoliciesUntouched(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        $this->artisan(self::COMMAND, ['apps_id' => $app->getId(), 'company_id' => $company->getId()])->assertSuccessful();

        $policy = $this->policy($app, $company, PeopleSalesforceSyncApprovalTypeEnum::CREATE->value);
        $policy->notify = 'none';
        $policy->saveOrFail();

        $this->artisan(self::COMMAND, ['apps_id' => $app->getId(), 'company_id' => $company->getId()])->assertSuccessful();

        $this->assertSame('none', $policy->refresh()->notify);
    }

    private function policy(Apps $app, $company, string $approvalType): ApprovalPolicy
    {
        return ApprovalPolicy::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('system_modules_id', SystemModulesRepository::getByModelName(People::class, $app)->getId())
            ->where('approval_type', $approvalType)
            ->firstOrFail();
    }
}
