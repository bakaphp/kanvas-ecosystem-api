<?php

declare(strict_types=1);

namespace Tests\Intelligence\FollowUp\Commands;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum as CompanyConfigurationEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\FollowUp\Jobs\DispatchAppLeadFollowUpsJob;
use Tests\TestCase;

/**
 * Verifies the hourly entry point honors the work-hours gate. The command
 * only dispatches DispatchAppLeadFollowUpsJob when CompanyWorkHoursTool
 * returns status='work_hours'; a company with no working-hours config is
 * always "after_hours", so nothing should be queued for it.
 */
class DispatchLeadFollowUpsCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social'];

    public function testDoesNotDispatchOutsideWorkHours(): void
    {
        Queue::fake();

        $app = app(Apps::class);

        // Enable the feature flag — otherwise the loop skips the app entirely
        // and we can't tell whether work-hours OR the flag stopped it.
        $app->set('use_lead_follow_up_v2', true);

        $company = Companies::factory()->create([
            'users_id' => auth()->user()->getId(),
            'timezone' => 'UTC',
        ]);
        $company->associateApp($app);

        $this->artisan('lead:dispatch-follow-ups')->assertExitCode(0);

        Queue::assertNotPushed(
            DispatchAppLeadFollowUpsJob::class,
            fn (DispatchAppLeadFollowUpsJob $job) => $job->company->getId() === $company->getId()
        );
    }

    public function testCompanyFlagDisablesFollowUpsInsideWorkHours(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-07 12:00:00', 'UTC'));

        $app = app(Apps::class);
        $app->set('use_lead_follow_up_v2', true);

        $enabled = $this->createCompanyOpenAllDay($app);
        $disabled = $this->createCompanyOpenAllDay($app);
        $disabled->set(ConfigurationEnum::LEAD_FOLLOW_UP_DISABLED->value, true);

        $this->artisan('lead:dispatch-follow-ups')->assertExitCode(0);

        Queue::assertPushed(
            DispatchAppLeadFollowUpsJob::class,
            fn (DispatchAppLeadFollowUpsJob $job) => $job->company->getId() === $enabled->getId()
        );
        Queue::assertNotPushed(
            DispatchAppLeadFollowUpsJob::class,
            fn (DispatchAppLeadFollowUpsJob $job) => $job->company->getId() === $disabled->getId()
        );

        Carbon::setTestNow();
    }

    private function createCompanyOpenAllDay(Apps $app): Companies
    {
        $company = Companies::factory()->create([
            'users_id' => auth()->user()->getId(),
            'timezone' => 'UTC',
        ]);
        $company->associateApp($app);
        $company->set(CompanyConfigurationEnum::WORKING_HOURS->value, [
            'opens_at_local' => '00:00:00',
            'closes_at_local' => '23:59:59',
        ]);

        return $company;
    }
}
