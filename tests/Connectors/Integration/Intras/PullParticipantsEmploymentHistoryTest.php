<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Actions\PullParticipantsFromIntrasAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Models\PeopleEmploymentHistory;
use Kanvas\Guild\Organizations\Models\Organization;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class PullParticipantsEmploymentHistoryTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm'];

    private Apps $kanvasApp;
    private Companies $kanvasCompany;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kanvasApp = app(Apps::class);
        $this->kanvasCompany = static::$cachedUser->getCurrentCompany();
    }

    public function test_links_current_role_to_employment_history_idempotently(): void
    {
        $org = $this->seedOrganization('Intras Corp ' . uniqid());
        $people = $this->seedPeople();

        $action = new PullParticipantsFromIntrasAction($this->kanvasApp, $this->kanvasCompany, static::$cachedUser);
        $this->seedOrganizationMap($action, [555 => $org->getId()]);

        $this->linkToOrganization($action, $people, 555, 'Gerente General');

        $rows = $this->employmentRows($people, $org);
        $this->assertCount(1, $rows, 'A single current-role row is created for the current employer.');
        $this->assertSame(1, (int) $rows[0]->status, 'Intras current role is recorded with status=1.');
        $this->assertSame('Gerente General', $rows[0]->position);

        // Re-running the sync with a changed title must not duplicate the row — Intras
        // owns the title, so it stays in sync via updateOrCreate.
        $this->linkToOrganization($action, $people, 555, 'VP Operaciones');

        $rows = $this->employmentRows($people, $org);
        $this->assertCount(1, $rows, 'Re-sync keeps a single row (idempotent).');
        $this->assertSame('VP Operaciones', $rows[0]->position, 'Title is synced to the latest Intras value.');
    }

    public function test_skips_employment_history_when_company_not_mapped(): void
    {
        $people = $this->seedPeople();

        $action = new PullParticipantsFromIntrasAction($this->kanvasApp, $this->kanvasCompany, static::$cachedUser);

        // Unmapped intras company id → no organization resolved → no employment row.
        $this->linkToOrganization($action, $people, 999, 'Whatever');

        $this->assertSame(
            0,
            PeopleEmploymentHistory::where('peoples_id', $people->getId())->count(),
            'No org means no employment-history row is written.',
        );
    }

    public function test_records_current_role_even_when_position_is_null(): void
    {
        $org = $this->seedOrganization('Intras Corp ' . uniqid());
        $people = $this->seedPeople();

        $action = new PullParticipantsFromIntrasAction($this->kanvasApp, $this->kanvasCompany, static::$cachedUser);
        $this->seedOrganizationMap($action, [777 => $org->getId()]);

        $this->linkToOrganization($action, $people, 777, null);

        $rows = $this->employmentRows($people, $org);
        $this->assertCount(1, $rows, 'The employer link is still recorded when Intras has no title.');
        $this->assertNull($rows[0]->position);
    }

    private function seedOrganizationMap(PullParticipantsFromIntrasAction $action, array $map): void
    {
        $property = new ReflectionProperty(PullParticipantsFromIntrasAction::class, 'organizationIdMap');
        $property->setValue($action, $map);
    }

    private function linkToOrganization(PullParticipantsFromIntrasAction $action, People $people, int $intrasCompanyId, ?string $position): void
    {
        $method = new ReflectionMethod(PullParticipantsFromIntrasAction::class, 'linkToOrganization');
        $method->invoke($action, $people, $intrasCompanyId, $position);
    }

    private function employmentRows(People $people, Organization $org)
    {
        return PeopleEmploymentHistory::where('peoples_id', $people->getId())
            ->where('organizations_id', $org->getId())
            ->get();
    }

    private function seedOrganization(string $name): Organization
    {
        return Organization::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->kanvasCompany->getId(),
            'users_id' => static::$cachedUser->getId(),
            'name' => $name,
            'address' => '',
            'total_employees' => 0,
        ]);
    }

    private function seedPeople(): People
    {
        return People::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->kanvasCompany->getId())
            ->withUserId(static::$cachedUser->getId())
            ->create();
    }
}
