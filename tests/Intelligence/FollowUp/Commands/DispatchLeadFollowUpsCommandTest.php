<?php

declare(strict_types=1);

namespace Tests\Intelligence\FollowUp\Commands;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
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
}
