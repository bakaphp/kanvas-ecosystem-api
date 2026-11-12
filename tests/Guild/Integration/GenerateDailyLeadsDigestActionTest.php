<?php

declare(strict_types=1);

namespace Tests\Guild\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Actions\GenerateDailyLeadsDigestAction;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadSource;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\Users;
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

        $result = new GenerateDailyLeadsDigestAction($app, collect([$company]))->execute(dryRun: true);

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
            $result = new GenerateDailyLeadsDigestAction($app, collect([$company]), 1)->execute();

            $this->assertSame(0, $result['total']);
            $this->assertFalse($result['sent']);
            Notification::assertNothingSent();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testCombinedDigestCoversEveryCompanyAndIsSentOnlyToItsRecipient(): void
    {
        Notification::fake();

        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $firstCompany = $user->getCurrentCompany();
        $secondCompany = Companies::factory()->create(['users_id' => $user->getId(), 'is_active' => 1]);
        $firstCompany->set(ConfigurationEnum::DAILY_LEADS_DIGEST_RECIPIENTS->value, 'company-recipient@mail.test');
        Lead::factory()
            ->withAppAndCompany($app->getId(), $firstCompany->getId())
            ->create(['created_at' => now()->subHour()]);
        Lead::factory()
            ->withAppAndCompany($app->getId(), $secondCompany->getId())
            ->create(['created_at' => now()->subHour()]);

        $result = new GenerateDailyLeadsDigestAction(
            app: $app,
            companies: collect([$firstCompany, $secondCompany]),
            hours: 24,
            recipient: $user,
        )->execute();

        $this->assertTrue($result['is_combined']);
        $this->assertNull($result['company']);
        $this->assertSame(2, $result['companies_count']);
        $this->assertEqualsCanonicalizing(
            [$firstCompany->name, $secondCompany->name],
            array_column($result['by_company'], 'name')
        );
        $this->assertContains($secondCompany->name, array_column($result['leads'], 'company'));
        $this->assertTrue($result['sent']);
        Notification::assertSentOnDemandTimes(Blank::class, 1);
        Notification::assertSentOnDemand(
            Blank::class,
            fn (Blank $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === $user->email
        );
    }

    public function testCompanyDigestSkipsRecipientsWhoReceiveTheCombinedDigest(): void
    {
        Notification::fake();

        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $company->set(
            ConfigurationEnum::DAILY_LEADS_DIGEST_RECIPIENTS->value,
            'combined-user@mail.test,manager@mail.test'
        );
        Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create(['created_at' => now()->subHour()]);

        $result = new GenerateDailyLeadsDigestAction($app, collect([$company]), 24)
            ->execute(skipRecipients: ['Combined-User@mail.test']);

        $this->assertFalse($result['is_combined']);
        $this->assertTrue($result['sent']);
        Notification::assertSentOnDemandTimes(Blank::class, 1);
        Notification::assertSentOnDemand(
            Blank::class,
            fn (Blank $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'manager@mail.test'
        );
    }

    public function testItSummarizesConfiguredCustomFields(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $company->set(ConfigurationEnum::DAILY_LEADS_DIGEST_SUMMARY_FIELDS->value, 'vehicle_of_interest');
        $withVehicle = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create(['created_at' => now()->subHour()]);
        $withVehicle->set('vehicle_of_interest', ['make' => 'Toyota', 'model' => 'Corolla', 'yearFrom' => 2022]);
        Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create(['created_at' => now()->subHour()]);

        $result = new GenerateDailyLeadsDigestAction($app, collect([$company]), 1)->execute(dryRun: true);

        $summary = $result['custom_field_summaries']['vehicle_of_interest'];
        $this->assertSame($result['total'] - 1, $summary['missing']);
        $this->assertSame([['name' => 'Toyota Corolla 2022', 'count' => 1]], $summary['top']);
        $this->assertContains($withVehicle->getId(), array_column($result['leads'], 'id'));
    }
}
