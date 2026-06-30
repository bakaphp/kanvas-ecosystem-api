<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Apollo;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Apollo\Actions\BackfillJobChangeEventAction;
use Kanvas\Connectors\Apollo\Enums\ConfigurationEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Models\PeopleEmploymentHistory;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Tests\TestCase;

final class BackfillJobChangeEventActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence'];

    public function test_emits_people_enriched_for_a_real_move(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();
        $suffix = uniqid();

        $person = $this->personWithJobChange($app, $company, [
            'changed_at' => '2026-01-15T10:00:00+00:00',
            'from_company' => "Claro {$suffix}",
            'from_title' => 'Analista',
            'to_company' => "Aleli {$suffix}",
            'to_title' => 'Supervisora de Operaciones',
        ]);

        $result = new BackfillJobChangeEventAction($person, $app, $person->company)->execute();

        $this->assertSame(BackfillJobChangeEventAction::EMITTED, $result);

        $event = $this->ledgerEventQuery($app, $person)->orderByDesc('id')->first();
        $this->assertNotNull($event);

        $payload = (array) $event->payload;
        $this->assertSame('apollo', $payload['source']);
        $this->assertTrue($payload['backfilled']);
        $this->assertSame("Aleli {$suffix}", $payload['company']);
        $this->assertContains('current_employer', $payload['changed_fields']);
        $this->assertContains('title', $payload['changed_fields']);
        $this->assertSame("Claro {$suffix}", $payload['changes']['current_employer']['from']);
        $this->assertSame("Aleli {$suffix}", $payload['changes']['current_employer']['to']);
        $this->assertSame('Analista', $payload['changes']['title']['from']);
        $this->assertSame('Supervisora de Operaciones', $payload['changes']['title']['to']);
        $this->assertSame('2026-01-15', $event->occurred_at->toDateString());
    }

    public function test_rerun_is_idempotent(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();
        $suffix = uniqid();

        $person = $this->personWithJobChange($app, $company, [
            'changed_at' => '2026-02-20T08:30:00+00:00',
            'from_company' => "Old {$suffix}",
            'from_title' => 'Rep',
            'to_company' => "New {$suffix}",
            'to_title' => 'Manager',
        ]);

        $first = new BackfillJobChangeEventAction($person, $app, $person->company)->execute();
        $second = new BackfillJobChangeEventAction($person, $app, $person->company)->execute();

        $this->assertSame(BackfillJobChangeEventAction::EMITTED, $first);
        $this->assertSame(BackfillJobChangeEventAction::SKIPPED_DUPLICATE, $second, 'A move already in the ledger is not re-emitted.');
        $this->assertSame(1, $this->ledgerEventQuery($app, $person)->count());
    }

    public function test_first_fill_with_empty_antes_is_not_a_move(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();
        $suffix = uniqid();

        $person = $this->personWithJobChange($app, $company, [
            'changed_at' => '2026-03-10T12:00:00+00:00',
            'from_company' => '',
            'from_title' => '',
            'to_company' => "Acme {$suffix}",
            'to_title' => 'Analyst',
        ]);

        $result = new BackfillJobChangeEventAction($person, $app, $person->company)->execute();

        $this->assertSame(BackfillJobChangeEventAction::SKIPPED_NO_CHANGE, $result);
        $this->assertSame(0, $this->ledgerEventQuery($app, $person)->count());
    }

    public function test_skips_a_move_to_a_past_employer(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();
        $suffix = uniqid();

        $person = $this->personWithJobChange($app, $company, [
            'changed_at' => '2026-05-01T09:00:00+00:00',
            'from_company' => "Alpha {$suffix}",
            'from_title' => 'Gerente',
            'to_company' => "Baninter {$suffix}",
            'to_title' => 'Gerente',
        ]);

        // Her real current employer is Alpha; Baninter is a past role — the stored blob's
        // "move to Baninter" is the corrupt false move that must NOT be re-emitted.
        $alpha = $this->seedOrg($app, $company, "Alpha {$suffix}");
        $baninter = $this->seedOrg($app, $company, "Baninter {$suffix}");
        $this->seedEmployment($app, $person, $alpha, status: 1, endDate: null);
        $this->seedEmployment($app, $person, $baninter, status: 0, endDate: '2001-08-01');

        $result = new BackfillJobChangeEventAction($person, $app, $person->company)->execute();

        $this->assertSame(BackfillJobChangeEventAction::SKIPPED_PAST_EMPLOYER, $result);
        $this->assertSame(0, $this->ledgerEventQuery($app, $person)->count(), 'A false move to a past employer is never backfilled.');
    }

    public function test_dry_run_does_not_write(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();
        $suffix = uniqid();

        $person = $this->personWithJobChange($app, $company, [
            'changed_at' => '2026-04-01T09:00:00+00:00',
            'from_company' => "Prev {$suffix}",
            'from_title' => 'Junior',
            'to_company' => "Next {$suffix}",
            'to_title' => 'Senior',
        ]);

        $result = new BackfillJobChangeEventAction($person, $app, $person->company)->execute(dryRun: true);

        $this->assertSame(BackfillJobChangeEventAction::WOULD_EMIT, $result);
        $this->assertSame(0, $this->ledgerEventQuery($app, $person)->count(), 'Dry-run writes nothing.');
    }

    /**
     * @param array<string, string> $change
     */
    private function personWithJobChange(Apps $app, Companies $company, array $change): People
    {
        $person = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId(static::$cachedUser->getId())
            ->create();

        $person->set(ConfigurationEnum::APOLLO_JOB_CHANGED_AT->value, time());
        $person->set(ConfigurationEnum::APOLLO_LAST_JOB_CHANGE->value, $change);

        return $person;
    }

    private function seedOrg(Apps $app, Companies $company, string $name): Organization
    {
        return Organization::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => static::$cachedUser->getId(),
            'name' => $name,
            'address' => '',
            'total_employees' => 0,
        ]);
    }

    private function seedEmployment(Apps $app, People $person, Organization $org, int $status, ?string $endDate): void
    {
        PeopleEmploymentHistory::create([
            'apps_id' => $app->getId(),
            'peoples_id' => $person->getId(),
            'organizations_id' => $org->getId(),
            'position' => 'Role',
            'start_date' => '2017-01-01',
            'end_date' => $endDate,
            'status' => $status,
            'is_deleted' => 0,
        ]);
    }

    private function ledgerEventQuery(Apps $app, People $person): Builder
    {
        return Event::query()
            ->where('apps_id', $app->getId())
            ->where('event_type', 'people.enriched')
            ->where('source_entity_type', People::class)
            ->where('source_entity_id', (int) $person->getId());
    }
}
