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
use Kanvas\Connectors\VinSolution\Exceptions\VinSolutionException;
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

    public function testVinSolutionResolvesToNullWhenTheOwnerIsNotMapped(): void
    {
        $lead = $this->makeLead();

        $lead->user->set(
            VinSolutionConfigurationEnum::getUserKey($lead->company, $lead->user),
            '111'
        );

        $this->assertNull(VinSolutionLeadUserService::resolve($lead));
    }

    public function testVinSolutionResolvesToNullOnAnUnassignedLeadEvenWhenTheLeadUserIsMapped(): void
    {
        $lead = $this->makeLead();
        $lead->leads_owner_id = 0;
        $lead->saveQuietly();
        $lead->refresh();
        $lead->user->set(
            VinSolutionConfigurationEnum::getUserKey($lead->company, $lead->user),
            '111'
        );

        $this->assertNull($lead->owner, 'precondition: the lead is unassigned');
        $this->assertNull(
            VinSolutionLeadUserService::resolve($lead),
            'the creating account must not become the sales rep; VinSolutions assigns unassigned leads itself'
        );
    }

    public function testVinSolutionActsAsTheMappedOwnerWhenThereIsOne(): void
    {
        $lead = $this->makeLead();
        $lead->owner->set(
            VinSolutionConfigurationEnum::getUserKey($lead->company, $lead->owner),
            '222'
        );

        $this->assertSame($lead->owner->getId(), VinSolutionLeadUserService::actingUser($lead)->getId());
    }

    public function testVinSolutionActsAsTheCompanyOwnerOnAnUnassignedLead(): void
    {
        $lead = $this->makeLead();
        $lead->leads_owner_id = 0;
        $lead->saveQuietly();
        $lead->refresh();
        $lead->company->del(VinSolutionConfigurationEnum::DOWNLOAD_ALL_LEADS_USER->value);

        $acting = VinSolutionLeadUserService::actingUser($lead);

        $this->assertSame($lead->company->user->getId(), $acting->getId());
        $this->assertNotSame($lead->user->getId(), $acting->getId());
    }

    public function testVinSolutionActsAsTheDownloadUserWhenTheCompanyOptedIn(): void
    {
        $lead = $this->makeLead();
        $lead->leads_owner_id = 0;
        $lead->saveQuietly();
        $lead->refresh();
        $downloadUser = Users::factory()->create();
        $lead->company->set(VinSolutionConfigurationEnum::DOWNLOAD_ALL_LEADS_USER->value, $downloadUser->getId());

        try {
            $this->assertSame($downloadUser->getId(), VinSolutionLeadUserService::actingUser($lead)->getId());
        } finally {
            $lead->company->del(VinSolutionConfigurationEnum::DOWNLOAD_ALL_LEADS_USER->value);
        }
    }

    public function testVinSolutionRefusesAnUnmappedOperatingUserWithAClearMessage(): void
    {
        $lead = $this->makeLead();
        $company = $lead->company;
        $company->del(VinSolutionConfigurationEnum::DOWNLOAD_ALL_LEADS_USER->value);
        $company->user->del(VinSolutionConfigurationEnum::getUserKey($company, $company->user));

        $this->expectException(VinSolutionException::class);
        $this->expectExceptionMessage(VinSolutionConfigurationEnum::DOWNLOAD_ALL_LEADS_USER->value);

        VinSolutionLeadUserService::mappedOperatingUserOrFail($company);
    }

    public function testDriveCentricPrefersTheMappedOwnerOverTheLeadUser(): void
    {
        $lead = $this->makeLead();

        $lead->user->set(DriveCentricConfigurationEnum::getUserKey($lead->company), '111');
        $lead->owner->set(DriveCentricConfigurationEnum::getUserKey($lead->company), '222');

        $this->assertSame($lead->owner->getId(), DriveCentricLeadUserService::resolve($lead)->getId());
    }

    public function testDriveCentricResolvesToNullOnAnUnassignedLeadEvenWhenTheLeadUserIsMapped(): void
    {
        $lead = $this->makeLead();
        $lead->leads_owner_id = 0;
        $lead->saveQuietly();
        $lead->refresh();
        $lead->user->set(DriveCentricConfigurationEnum::getUserKey($lead->company), '111');

        $this->assertNull($lead->owner, 'precondition: the lead is unassigned');
        $this->assertNull(
            DriveCentricLeadUserService::resolve($lead),
            'the creating account must not become salesperson1; DriveCentric assigns unassigned leads itself'
        );
    }

    public function testDriveCentricResolvesToNullWhenTheOwnerIsNotMapped(): void
    {
        $lead = $this->makeLead();
        $lead->user->set(DriveCentricConfigurationEnum::getUserKey($lead->company), '111');

        $this->assertNull(DriveCentricLeadUserService::resolve($lead));
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
