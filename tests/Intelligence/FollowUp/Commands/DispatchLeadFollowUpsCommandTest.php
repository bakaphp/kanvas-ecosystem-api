<?php

declare(strict_types=1);

namespace Tests\Intelligence\FollowUp\Commands;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum as CompanyConfigurationEnum;
use Kanvas\Intelligence\FollowUp\Jobs\DispatchAppLeadFollowUpsJob;
use Tests\TestCase;

/**
 * Verifies the hourly entry point honors the work-hours gate. The command
 * only dispatches DispatchAppLeadFollowUpsJob when CompanyWorkHoursTool
 * returns status='open'. We force "after_hours" by clearing the company's
 * work-hours config and confirm nothing is queued.
 */
class DispatchLeadFollowUpsCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social'];

    public function testDoesNotDispatchOutsideWorkHours(): void
    {
        Queue::fake();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // Enable the feature flag — otherwise the loop skips the app entirely
        // and we can't tell whether work-hours OR the flag stopped it.
        $app->set('use_lead_follow_up_v2', true);

        // Clear work-hours config to force "after_hours" status from the tool.
        $company->set(CompanyConfigurationEnum::WORKING_HOURS->value, null);
        $company->set(CompanyConfigurationEnum::WORKING_DAYS->value, []);

        $this->artisan('lead:dispatch-follow-ups')->assertExitCode(0);

        Queue::assertNotPushed(DispatchAppLeadFollowUpsJob::class);
    }
}
