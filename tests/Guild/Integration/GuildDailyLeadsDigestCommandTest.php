<?php

declare(strict_types=1);

namespace Tests\Guild\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class GuildDailyLeadsDigestCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'ecosystem'];

    public function testItExecutesWithoutSymfonyCommandMethodCollision(): void
    {
        $this->artisan('kanvas-guild:daily-leads-digest', [
            '--app_id' => 2147483647,
            '--company_id' => 2147483647,
            '--dry-run' => true,
        ])
            ->expectsOutput('Daily leads digest: 0 processed, 0 failed.')
            ->assertSuccessful();
    }

    public function testOptedInUserGetsOneDigestForEveryEnabledCompanyTheyBelongTo(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $firstCompany = $user->getCurrentCompany();
        [$secondCompany, $disabledCompany] = Companies::factory()
            ->count(2)
            ->create(['users_id' => $user->getId(), 'is_active' => 1])
            ->all();

        foreach ([$firstCompany, $secondCompany] as $company) {
            $company->set(ConfigurationEnum::DAILY_LEADS_DIGEST_ENABLED->value, true);
            Lead::factory()
                ->withAppAndCompany($app->getId(), $company->getId())
                ->create(['created_at' => now()->subHour()]);
        }
        $disabledCompany->set(ConfigurationEnum::DAILY_LEADS_DIGEST_ENABLED->value, false);
        $user->set(ConfigurationEnum::DAILY_LEADS_DIGEST_COMBINED->value, true);

        $this->artisan('kanvas-guild:daily-leads-digest', [
            '--app_id' => $app->getId(),
            '--user_id' => $user->getId(),
            '--dry-run' => true,
        ])
            ->expectsOutputToContain(sprintf('app=%d user=%d companies=2 ', $app->getId(), $user->getId()))
            ->assertSuccessful();
    }
}
