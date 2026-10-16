<?php

declare(strict_types=1);

namespace Tests\Guild\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Actions\GenerateDailyLeadsDigestAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadSource;
use Kanvas\Guild\Leads\Models\LeadType;
use Tests\TestCase;

final class GenerateDailyLeadsDigestActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'ecosystem'];

    public function testItBuildsMetricsOnlyForLeadsInsideTheConfiguredWindow(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $type = LeadType::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => 'Digest Type ' . uniqid(),
            'description' => 'Digest test type',
            'is_active' => 1,
        ]);
        $sourceName = 'Digest Source ' . uniqid();
        $source = LeadSource::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => $sourceName,
            'description' => 'Digest test source',
            'is_active' => 1,
            'leads_types_id' => $type->getId(),
        ]);
        $recentLead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create([
                'firstname' => 'Digest',
                'lastname' => 'Prospect',
                'email' => 'prospect-' . uniqid() . '@mail.test',
                'leads_sources_id' => $source->getId(),
                'created_at' => now()->subHours(3),
            ]);
        Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create([
                'created_at' => now()->subDays(2),
            ]);

        $result = new GenerateDailyLeadsDigestAction($app, $company)->execute(dryRun: true);

        $this->assertSame(1, $result['total']);
        $this->assertSame(0, $result['suspicious_emails_count']);
        $this->assertCount(1, $result['leads']);
        $this->assertSame($recentLead->people->name, $result['leads'][0]['name']);
        $this->assertSame($recentLead->people->emails->first()?->value, $result['leads'][0]['email']);
        $this->assertSame($recentLead->people->phones->first()?->value, $result['leads'][0]['phone']);
        $this->assertSame($sourceName, $result['leads'][0]['source']);
        $this->assertSame($recentLead->branch?->name ?? 'Default', $result['leads'][0]['branch']);
        $this->assertArrayHasKey('custom_fields', $result['leads'][0]);
        $this->assertSame([['name' => $sourceName, 'count' => 1]], $result['top_sources']);
        $this->assertSame([['name' => $result['leads'][0]['branch'], 'count' => 1]], $result['top_branches']);
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
