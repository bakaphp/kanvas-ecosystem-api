<?php

declare(strict_types=1);

namespace Tests\Guild\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Actions\GenerateDailyLeadsDigestAction;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

final class GenerateDailyLeadsDigestActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'ecosystem'];

    public function testItBuildsMetricsOnlyForLeadsInsideTheConfiguredWindow(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $recentLead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create([
                'firstname' => 'Digest',
                'lastname' => 'Prospect',
                'email' => 'prospect-' . uniqid() . '@mail.test',
                'created_at' => now()->subHours(3),
            ]);
        Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create([
                'created_at' => now()->subDays(2),
            ]);

        $result = new GenerateDailyLeadsDigestAction($app, $company)->execute(dryRun: true);

        $this->assertSame(1, $result['total']);
        $this->assertSame(1, $result['no_vehicle_count']);
        $this->assertSame(100.0, $result['no_vehicle_pct']);
        $this->assertSame(0, $result['suspicious_emails_count']);
        $this->assertCount(1, $result['leads']);
        $this->assertSame('Digest Prospect', $result['leads'][0]['name']);
        $this->assertSame(1, $result['top_dealers'][0]['count']);
        $this->assertSame([], $result['top_vehicles']);
        $this->assertSame($recentLead->created_at->toDateString(), $result['by_day'][0]['date']);
        $this->assertFalse($result['sent']);
    }

    public function testItDoesNotSendDigestWhenThereAreNoLeadsInThePeriod(): void
    {
        Notification::fake();

        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        Carbon::setTestNow(now()->addYears(10));
        try {
            $result = new GenerateDailyLeadsDigestAction($app, $company, 1)->execute();

            $this->assertSame(0, $result['total']);
            $this->assertFalse($result['sent']);
            Notification::assertNothingSent();
        } finally {
            Carbon::setTestNow();
        }
    }
}
