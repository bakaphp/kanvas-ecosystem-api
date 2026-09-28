<?php

declare(strict_types=1);

namespace Tests\Guild\Customers\Activities;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Activities\RequestPeopleContentApprovalActivity;
use Kanvas\Guild\Customers\Enums\PeopleApprovalTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\TestCase;

final class RequestPeopleContentApprovalActivityTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm'];

    public function testOpensContentApprovalForTheLeadsPeople(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->seedContentPolicy($app, $company);

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();

        $result = $this->activity()->execute($lead, $app, []);

        $this->assertTrue($result['requested']);

        $request = ApprovalRequest::find($result['approval_request_id']);
        $this->assertSame(PeopleApprovalTypeEnum::CONTENT->value, $request->approval_type);
        $this->assertSame($lead->getId(), $request->payload['lead_id']);
        $this->assertSame($lead->people->getId(), $request->payload['people_id']);
    }

    public function testDoesNotReopenWhenAlreadyPending(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->seedContentPolicy($app, $company);

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();

        $first = $this->activity()->execute($lead, $app, []);
        $second = $this->activity()->execute($lead, $app, []);

        $this->assertTrue($first['requested']);
        $this->assertFalse($second['requested']);
        $this->assertSame('already pending', $second['reason']);
    }

    public function testSupersedesTheOlderPendingRequestWhenAutoRejectStalePendingIsEnabled(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->seedContentPolicy($app, $company);

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();
        $first = $this->activity()->execute($lead, $app, []);

        $secondLead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->withPeopleId($lead->people->getId())
            ->create();
        $second = $this->activity()->execute($secondLead, $app, ['auto_reject_stale_pending' => true]);

        $this->assertTrue($second['requested']);
        $this->assertNotSame($first['approval_request_id'], $second['approval_request_id']);

        $old = ApprovalRequest::find($first['approval_request_id']);
        $new = ApprovalRequest::find($second['approval_request_id']);

        $this->assertSame('rejected', $old->status->value);
        $this->assertSame('Superseded by a more recent approval request', $old->reason);
        $this->assertSame('pending', $new->status->value);
    }

    private function activity(): RequestPeopleContentApprovalActivity
    {
        return new RequestPeopleContentApprovalActivity(0, now()->toDateTimeString(), StoredWorkflow::make(), []);
    }

    private function seedContentPolicy(Apps $app, $company): void
    {
        $systemModule = SystemModulesRepository::getByModelName(People::class, $app);

        ApprovalPolicy::firstOrCreate([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'system_modules_id' => $systemModule->getId(),
            'approval_type' => PeopleApprovalTypeEnum::CONTENT->value,
        ], [
            'steps' => [['step' => 1, 'resolver' => 'company_owner', 'config' => [], 'required_approvals' => 1]],
            'trigger' => ApprovalTriggerEnum::MANUAL,
            'reject_policy' => 'any',
            'notify' => 'all',
        ]);
    }
}
