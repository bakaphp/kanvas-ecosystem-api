<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DriveCentric\Enums\ConfigurationEnum as DriveCentricConfigurationEnum;
use Kanvas\Connectors\DriveCentric\Services\LeadUserService as DriveCentricLeadUserService;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum as EleadCustomFieldEnum;
use Kanvas\Connectors\Elead\Services\LeadUserService as EleadLeadUserService;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum as VinSolutionConfigurationEnum;
use Kanvas\Connectors\VinSolution\Services\LeadUserService as VinSolutionLeadUserService;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class LeadCrmUserResolutionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm'];

    public function testEleadPrefersTheMappedOwnerOverTheLeadUser(): void
    {
        $lead = $this->makeLead();

        $this->mapEleadUser($lead->user, $lead);
        $this->mapEleadUser($lead->owner, $lead);

        $this->assertSame(
            $lead->owner->getId(),
            EleadLeadUserService::resolve($lead, requireJobPosition: true)?->getId()
        );
    }

    public function testEleadFallsBackToTheLeadUserWhenTheOwnerIsNotMapped(): void
    {
        $lead = $this->makeLead();

        $this->mapEleadUser($lead->user, $lead);

        $this->assertSame(
            $lead->user->getId(),
            EleadLeadUserService::resolve($lead, requireJobPosition: true)?->getId()
        );
    }

    public function testEleadFallsBackToTheLeadUserWhenTheOwnerHasNoJobPosition(): void
    {
        $lead = $this->makeLead();

        $this->mapEleadUser($lead->owner, $lead, withJobPosition: false);
        $this->mapEleadUser($lead->user, $lead);

        $this->assertSame(
            $lead->user->getId(),
            EleadLeadUserService::resolve($lead, requireJobPosition: true)?->getId()
        );
        $this->assertSame(
            $lead->owner->getId(),
            EleadLeadUserService::resolve($lead)?->getId(),
            'without the job position requirement the owner is still the primary salesperson'
        );
    }

    public function testEleadResolvesToNullWhenNobodyIsMapped(): void
    {
        $this->assertNull(EleadLeadUserService::resolve($this->makeLead()));
    }

    public function testVinSolutionPrefersTheMappedOwnerOverTheLeadUser(): void
    {
        $lead = $this->makeLead();

        $lead->user->set(
            VinSolutionConfigurationEnum::getUserKey($lead->company, $lead->user),
            '111'
        );
        $lead->owner->set(
            VinSolutionConfigurationEnum::getUserKey($lead->company, $lead->owner),
            '222'
        );

        $this->assertSame($lead->owner->getId(), VinSolutionLeadUserService::resolve($lead)->getId());
    }

    public function testVinSolutionFallsBackToTheLeadUserWhenTheOwnerIsNotMapped(): void
    {
        $lead = $this->makeLead();

        $lead->user->set(
            VinSolutionConfigurationEnum::getUserKey($lead->company, $lead->user),
            '111'
        );

        $this->assertSame($lead->user->getId(), VinSolutionLeadUserService::resolve($lead)->getId());
    }

    public function testVinSolutionResolvesToNullWhenTheLeadHasNeitherAnOwnerNorAUser(): void
    {
        $lead = $this->makeLead();
        $lead->leads_owner_id = 0;
        $lead->users_id = 0;
        $lead->saveQuietly();
        $lead->refresh();

        $this->assertNull(VinSolutionLeadUserService::resolve($lead));
    }

    public function testDriveCentricPrefersTheMappedOwnerOverTheLeadUser(): void
    {
        $lead = $this->makeLead();

        $lead->user->set(DriveCentricConfigurationEnum::getUserKey($lead->company), '111');
        $lead->owner->set(DriveCentricConfigurationEnum::getUserKey($lead->company), '222');

        $this->assertSame($lead->owner->getId(), DriveCentricLeadUserService::resolve($lead)->getId());
    }

    public function testDriveCentricFallsBackToTheLeadUserOnAnUnassignedLead(): void
    {
        $lead = $this->makeLead();
        $lead->leads_owner_id = 0;
        $lead->saveQuietly();
        $lead->refresh();

        $this->assertNull($lead->owner, 'precondition: the lead is unassigned');
        $this->assertSame($lead->user->getId(), DriveCentricLeadUserService::resolve($lead)->getId());
    }

    private function mapEleadUser(Users $user, Lead $lead, bool $withJobPosition = true): void
    {
        $user->set(EleadCustomFieldEnum::getUserKey($lead->company), (string) $user->getId());

        if ($withJobPosition) {
            $user->set(EleadCustomFieldEnum::getUserJobPositionKey($lead->company), 'SP');
        }
    }

    private function makeLead(): Lead
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $lead = Lead::factory()
            ->withUserId(Users::factory()->create()->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $lead->leads_owner_id = Users::factory()->create()->getId();
        $lead->saveQuietly();

        return $lead->refresh();
    }
}
