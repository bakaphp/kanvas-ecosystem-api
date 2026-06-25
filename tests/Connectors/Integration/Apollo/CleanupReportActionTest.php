<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Apollo;

use Baka\Contracts\CompanyInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Apollo\Actions\CleanupReportAction;
use Kanvas\Guild\Customers\Enums\ContactValidationStatusEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Tests\TestCase;

final class CleanupReportActionTest extends TestCase
{
    use DatabaseTransactions;

    // Far-future window isolates the seeded events from any real people.enriched data.
    private const string FROM = '2031-01-01';
    private const string TO = '2031-01-31';

    protected array $connectionsToTransact = ['mysql', 'crm'];

    protected function tearDown(): void
    {
        DB::connection('intelligence')
            ->table('nervous_system_events')
            ->where('event_type', 'people.enriched')
            ->whereBetween('occurred_at', [self::FROM . ' 00:00:00', self::TO . ' 23:59:59'])
            ->delete();

        parent::tearDown();
    }

    public function test_aggregates_changes_and_company_freshness(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        $this->seedEnriched(90001, 'Banco Popular', ['current_employer' => ['from' => 'X', 'to' => 'Banco Popular'], 'title' => ['from' => 'a', 'to' => 'b']]);
        $this->seedEnriched(90002, 'Banco Popular', []); // al día — no changes
        $this->seedEnriched(90003, 'Grupo Ramos', ['email_changed' => ['from' => 'a@x.do', 'to' => 'b@y.do']]);
        $this->seedEnriched(90004, 'Grupo Ramos', ['seniority_promoted' => ['from' => 'manager', 'to' => 'director'], 'new_account' => true]);

        $baselineBouncing = $this->runReport($app, $company)['bouncingLeads'];
        $this->seedBouncingLead($app, $company);

        $report = $this->runReport($app, $company);

        $this->assertSame($baselineBouncing + 1, $report['bouncingLeads']);
        $this->assertSame(4, $report['verifiedLeads']);
        $this->assertSame(1, $report['changedCompany']);
        $this->assertSame(1, $report['changedTitle']);
        $this->assertSame(1, $report['changedEmail']);
        $this->assertSame(1, $report['promotedToDecisionMaker']);
        $this->assertSame(1, $report['newAccounts']);

        $byCompany = collect($report['byCompany'])->keyBy('companyName');

        $this->assertSame(2, $byCompany['Banco Popular']['total']);
        $this->assertSame(1, $byCompany['Banco Popular']['outdated']);
        $this->assertSame(1, $byCompany['Banco Popular']['upToDate']);

        $this->assertSame(2, $byCompany['Grupo Ramos']['total']);
        $this->assertSame(2, $byCompany['Grupo Ramos']['outdated']);
        $this->assertSame(0, $byCompany['Grupo Ramos']['upToDate']);
    }

    private function runReport(Apps $app, CompanyInterface $company): array
    {
        return new CleanupReportAction(
            app: $app,
            company: $company,
            from: Carbon::parse(self::FROM)->startOfDay(),
            to: Carbon::parse(self::TO)->endOfDay(),
        )->execute();
    }

    private function seedBouncingLead(Apps $app, CompanyInterface $company): void
    {
        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId(static::$cachedUser->getId())
            ->create();

        $email = 'bouncing-' . uniqid() . '@intras.test';
        $people->addEmail($email, 0, 0);

        $people->contacts()
            ->where('value', $email)
            ->firstOrFail()
            ->update(['validation_status' => ContactValidationStatusEnum::HARD_BOUNCE->value]);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function seedEnriched(int $entityId, string $companyName, array $changes): void
    {
        new AppendEventAction(
            new EventData(
                app: app(Apps::class),
                company: static::$cachedUser->getCurrentCompany(),
                sourceDomain: 'Guild',
                eventType: 'people.enriched',
                status: EventStatusEnum::INFO,
                sourceEntityType: People::class,
                sourceEntityId: $entityId,
                actorType: 'System',
                payload: [
                    'source' => 'apollo',
                    'company' => $companyName,
                    'changed_fields' => array_keys($changes),
                    'changes' => $changes,
                ],
                occurredAt: Carbon::parse(self::FROM)->addDays(5),
            ),
        )->execute();
    }
}
