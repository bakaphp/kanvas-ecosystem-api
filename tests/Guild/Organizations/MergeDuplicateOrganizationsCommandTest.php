<?php

declare(strict_types=1);

namespace Tests\Guild\Organizations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Organizations\Models\Organization;
use Tests\TestCase;

final class MergeDuplicateOrganizationsCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'accounting', 'intelligence'];

    private const string COMMAND = 'kanvas:guild-merge-duplicate-organizations';

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();
    }

    public function test_dry_run_reports_but_changes_nothing(): void
    {
        $tok = strtoupper('z' . uniqid());
        $target = $this->seedOrganization("Zeta {$tok}");
        $source = $this->seedOrganization("ZETA {$tok}, SRL");

        $this->artisan(self::COMMAND, ['app_id' => $this->kanvasApp->getId(), 'company_id' => $this->company->getId()])
            ->assertSuccessful();

        $source->refresh();
        $this->assertFalse((bool) $source->is_deleted, 'dry-run must not merge anything.');
    }

    public function test_force_merges_normalized_duplicate_and_rebinds_employment_history(): void
    {
        $tok = strtoupper('z' . uniqid());
        $target = $this->seedOrganization("Zeta {$tok}");          // oldest → survivor
        $source = $this->seedOrganization("ZETA {$tok}, S. A.");   // newer dup → merged away

        $ehId = DB::connection('crm')->table('peoples_employment_history')->insertGetId([
            'apps_id' => $this->kanvasApp->getId(),
            'peoples_id' => 7777,
            'organizations_id' => $source->id,
            'position' => 'Manager',
            'start_date' => '2021-01-01',
            'end_date' => null,
            'status' => 1,
            'is_deleted' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $this->artisan(self::COMMAND, ['app_id' => $this->kanvasApp->getId(), 'company_id' => $this->company->getId(), '--force' => true])
            ->assertSuccessful();

        $source->refresh();
        $target->refresh();
        $this->assertTrue((bool) $source->is_deleted, 'the duplicate is soft-deleted.');
        $this->assertFalse((bool) $target->is_deleted, 'the survivor stays.');
        $this->assertSame(
            (int) $target->id,
            (int) DB::connection('crm')->table('peoples_employment_history')->where('id', $ehId)->value('organizations_id'),
            'employment history follows the merge to the survivor.',
        );
    }

    private function seedOrganization(string $name): Organization
    {
        return Organization::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => static::$cachedUser->getId(),
            'name' => $name,
            'address' => '',
            'total_employees' => 0,
        ]);
    }
}
