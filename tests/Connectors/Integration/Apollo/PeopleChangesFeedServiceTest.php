<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Apollo;

use Baka\Contracts\CompanyInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Apollo\Services\PeopleChangesFeedService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Tests\TestCase;

final class PeopleChangesFeedServiceTest extends TestCase
{
    use DatabaseTransactions;

    private const string FROM = '2031-02-01';
    private const string TO = '2031-02-28';

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

    public function test_expands_each_changed_field_into_its_own_row(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        $person = $this->makePerson($app, $company, 'Anaunique', 'Rodriguezfeed');

        $this->seedEnriched($app, $company, (int) $person->getId(), 'Banco Popular', [
            'email_changed' => ['from' => 'old@x.com', 'to' => 'new@y.com'],
            'title' => ['from' => 'Analyst', 'to' => 'Manager'],
        ]);

        $rows = $this->feedRows($app, $company);

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(['email', 'title'], array_column($rows, 'type'));

        $email = collect($rows)->firstWhere('type', 'email');
        $this->assertSame('old@x.com', $email['from']);
        $this->assertSame('new@y.com', $email['to']);
        $this->assertSame('Banco Popular', $email['company']);
        $this->assertSame('Anaunique Rodriguezfeed', $email['person']);
        $this->assertSame($company->name, $email['crm']);
    }

    public function test_change_types_filter_limits_the_rows(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        $person = $this->makePerson($app, $company, 'Filteruniq', 'Personfeed');

        $this->seedEnriched($app, $company, (int) $person->getId(), 'Grupo Ramos', [
            'email_changed' => ['from' => 'a@x.com', 'to' => 'b@x.com'],
            'title' => ['from' => 'A', 'to' => 'B'],
        ]);

        $rows = new PeopleChangesFeedService($app, $company)->rows(
            changeTypes: ['email'],
            from: Carbon::parse(self::FROM)->startOfDay(),
            to: Carbon::parse(self::TO)->endOfDay(),
        );

        $mine = array_values(array_filter($rows, fn (array $r): bool => $r['person'] === 'Filteruniq Personfeed'));
        $this->assertCount(1, $mine);
        $this->assertSame('email', $mine[0]['type']);
    }

    public function test_new_account_drops_the_current_employer_row(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        $person = $this->makePerson($app, $company, 'Newaccountuniq', 'Personfeed');

        $this->seedEnriched($app, $company, (int) $person->getId(), 'Nueva Empresa', [
            'current_employer' => ['from' => 'Old Co', 'to' => 'Nueva Empresa'],
            'new_account' => true,
        ]);

        $rows = new PeopleChangesFeedService($app, $company)->rows(
            from: Carbon::parse(self::FROM)->startOfDay(),
            to: Carbon::parse(self::TO)->endOfDay(),
        );

        $mine = array_values(array_filter($rows, fn (array $r): bool => $r['person'] === 'Newaccountuniq Personfeed'));
        $this->assertSame([], $mine);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function feedRows(Apps $app, CompanyInterface $company): array
    {
        $rows = new PeopleChangesFeedService($app, $company)->rows(
            from: Carbon::parse(self::FROM)->startOfDay(),
            to: Carbon::parse(self::TO)->endOfDay(),
        );

        return array_values(array_filter(
            $rows,
            fn (array $r): bool => str_ends_with($r['person'], 'feed'),
        ));
    }

    private function makePerson(Apps $app, CompanyInterface $company, string $first, string $last): People
    {
        return People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId(static::$cachedUser->getId())
            ->create(['firstname' => $first, 'lastname' => $last]);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function seedEnriched(Apps $app, CompanyInterface $company, int $entityId, string $companyName, array $changes): void
    {
        new AppendEventAction(
            new EventData(
                app: $app,
                company: $company,
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
