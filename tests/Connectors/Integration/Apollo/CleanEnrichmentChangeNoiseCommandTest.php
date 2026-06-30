<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Apollo;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Models\PeopleEmploymentHistory;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Tests\TestCase;

final class CleanEnrichmentChangeNoiseCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence'];

    private const string COMMAND = 'kanvas:guild-apollo-clean-enrichment-noise';

    public function test_strips_fake_transitions_but_keeps_real_ones(): void
    {
        $app = app(Apps::class);
        $entityId = random_int(800000, 899999);

        $event = $this->seedEvent($app, $entityId, [
            'current_employer' => ['from' => '', 'to' => 'Before Boarding'],   // fake: empty from
            'title' => ['from' => 'analyst', 'to' => 'Analyst'],               // fake: from == to
            'seniority_promoted' => ['from' => '', 'to' => 'director'],        // fake: first-fill promotion
            'headline' => ['from' => 'Old headline', 'to' => 'New headline'],  // always-strip (not a feed row)
            'contacts_added' => ['5:http://www.linkedin.com/in/x'],            // always-strip (not a change)
            'email_changed' => ['from' => 'a@x.do', 'to' => 'b@y.do'],         // real: keep
            'new_account' => true,                                             // signal: keep
        ]);

        $this->artisan(self::COMMAND, ['app_id' => $app->getId(), '--force' => true])
            ->assertSuccessful();

        $event->refresh();
        $changes = (array) $event->payload['changes'];

        $this->assertArrayNotHasKey('current_employer', $changes, 'Empty-from move is stripped.');
        $this->assertArrayNotHasKey('title', $changes, 'Same-value title is stripped.');
        $this->assertArrayNotHasKey('seniority_promoted', $changes, 'First-fill promotion is stripped.');
        $this->assertArrayNotHasKey('headline', $changes, 'Headline is not a feed row.');
        $this->assertArrayNotHasKey('contacts_added', $changes, 'contacts_added is not a feed row.');
        $this->assertArrayHasKey('email_changed', $changes, 'A real change is kept.');
        $this->assertTrue($changes['new_account'], 'Non-transition signals are kept.');
        $this->assertEqualsCanonicalizing(['email_changed', 'new_account'], $event->payload['changed_fields']);
    }

    public function test_keeps_a_real_seniority_promotion(): void
    {
        $app = app(Apps::class);
        $entityId = random_int(800000, 899999);

        $event = $this->seedEvent($app, $entityId, [
            'seniority_promoted' => ['from' => 'senior', 'to' => 'director'],
        ]);

        $this->artisan(self::COMMAND, ['app_id' => $app->getId(), '--force' => true])
            ->assertSuccessful();

        $event->refresh();
        $this->assertArrayHasKey('seniority_promoted', (array) $event->payload['changes'], 'A real promotion (known prior seniority) is kept.');
    }

    public function test_dry_run_does_not_write(): void
    {
        $app = app(Apps::class);
        $entityId = random_int(800000, 899999);

        $event = $this->seedEvent($app, $entityId, [
            'current_employer' => ['from' => '', 'to' => 'Ghost Co'],
        ]);

        $this->artisan(self::COMMAND, ['app_id' => $app->getId()])
            ->assertSuccessful();

        $event->refresh();
        $this->assertArrayHasKey('current_employer', (array) $event->payload['changes'], 'Dry-run leaves the row untouched.');
    }

    public function test_prune_empty_deletes_fully_fake_events(): void
    {
        $app = app(Apps::class);
        $entityId = random_int(800000, 899999);

        $event = $this->seedEvent($app, $entityId, [
            'current_employer' => ['from' => '', 'to' => 'Nowhere Inc'],
        ]);
        $id = $event->id;

        $this->artisan(self::COMMAND, ['app_id' => $app->getId(), '--force' => true, '--prune-empty' => true])
            ->assertSuccessful();

        $this->assertNull(Event::query()->find($id), 'An event left with no real change is pruned.');
    }

    public function test_strips_a_move_to_a_past_employer(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();
        $suffix = uniqid();

        $person = $this->seedPerson($app, $company);
        $alpha = $this->seedOrg($app, $company, "Alpha {$suffix}");
        $baninter = $this->seedOrg($app, $company, "Baninter {$suffix}");

        // Alpha is her current (status 1) employer; Baninter is a past (status 0) role.
        $this->seedEmployment($app, $person, $alpha, status: 1, endDate: null);
        $this->seedEmployment($app, $person, $baninter, status: 0, endDate: '2001-08-01');

        // The false move recorded by the old enrichment: Alpha → Baninter (a job she left).
        $event = $this->seedEvent($app, (int) $person->getId(), [
            'current_employer' => ['from' => "Alpha {$suffix}", 'to' => "Baninter {$suffix}"],
        ], (int) $company->getId());

        $this->artisan(self::COMMAND, ['app_id' => $app->getId(), '--company_id' => $company->getId(), '--force' => true])
            ->assertSuccessful();

        $event->refresh();
        $changes = (array) $event->payload['changes'];
        $this->assertArrayNotHasKey('current_employer', $changes, 'A move to a past employer is stripped.');
    }

    public function test_keeps_a_move_to_the_genuine_current_employer(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();
        $suffix = uniqid();

        $person = $this->seedPerson($app, $company);
        $newCo = $this->seedOrg($app, $company, "New Co {$suffix}");

        // New Co IS her current employer — a real move to it must survive.
        $this->seedEmployment($app, $person, $newCo, status: 1, endDate: null);

        $event = $this->seedEvent($app, (int) $person->getId(), [
            'current_employer' => ['from' => "Old Co {$suffix}", 'to' => "New Co {$suffix}"],
        ], (int) $company->getId());

        $this->artisan(self::COMMAND, ['app_id' => $app->getId(), '--company_id' => $company->getId(), '--force' => true])
            ->assertSuccessful();

        $event->refresh();
        $changes = (array) $event->payload['changes'];
        $this->assertArrayHasKey('current_employer', $changes, 'A move to the genuine current employer is kept.');
        $this->assertSame("New Co {$suffix}", $changes['current_employer']['to']);
    }

    private function seedPerson(Apps $app, Companies $company): People
    {
        return People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId(static::$cachedUser->getId())
            ->create();
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

    /**
     * @param array<string, mixed> $changes
     */
    private function seedEvent(Apps $app, int $entityId, array $changes, int $companiesId = 0): Event
    {
        $event = new Event();
        $event->apps_id = $app->getId();
        $event->companies_id = $companiesId;
        $event->source_domain = 'Guild';
        $event->source_entity_type = People::class;
        $event->source_entity_id = $entityId;
        $event->event_type = 'people.enriched';
        $event->status = EventStatusEnum::INFO->value;
        $event->payload = [
            'source' => 'apollo',
            'company' => 'Test',
            'changed_fields' => array_keys($changes),
            'changes' => $changes,
        ];
        $event->occurred_at = now();
        $event->saveOrFail();

        return $event;
    }
}
