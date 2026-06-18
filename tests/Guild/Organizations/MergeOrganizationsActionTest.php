<?php

declare(strict_types=1);

namespace Tests\Guild\Organizations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Organizations\Actions\MergeOrganizationsAction;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationPeople;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers the bulk-merge cleanup mechanism used after PDF ingest creates duplicate Guild Org rows.
 * Tests the data-corrupting paths — FK rewrites across Scribe + Guild — and the guard clauses
 * that prevent operator mistakes.
 */
class MergeOrganizationsActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'accounting', 'intelligence'];

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();
    }

    public function test_rewrites_scribe_fks_when_merging(): void
    {
        $source = $this->seedOrganization('ACME Source');
        $target = $this->seedOrganization('ACME Target');

        // Insert bill + expense + invoice pointing at source — minimal columns so we don't need full COA
        $billId = DB::connection('accounting')->table('bills')->insertGetId(
            $this->minimalAccountingRow(['vendor_organization_id' => $source->id, 'bill_number' => 'B-1'])
            + $this->billDefaults(),
        );
        $expenseId = DB::connection('accounting')->table('expenses')->insertGetId(
            $this->minimalAccountingRow(['vendor_organization_id' => $source->id])
            + $this->expenseDefaults(),
        );
        $invoiceId = DB::connection('accounting')->table('invoices')->insertGetId(
            $this->minimalAccountingRow(['customer_organization_id' => $source->id, 'invoice_number' => 'I-1'])
            + $this->invoiceDefaults(),
        );

        new MergeOrganizationsAction(
            source: $source,
            target: $target,
            user: static::$cachedUser,
        )->execute();

        // Scribe FKs rewritten
        $this->assertSame(
            (int) $target->id,
            (int) DB::connection('accounting')->table('bills')->where('id', $billId)->value('vendor_organization_id'),
        );
        $this->assertSame(
            (int) $target->id,
            (int) DB::connection('accounting')->table('expenses')->where('id', $expenseId)->value('vendor_organization_id'),
        );
        $this->assertSame(
            (int) $target->id,
            (int) DB::connection('accounting')->table('invoices')->where('id', $invoiceId)->value('customer_organization_id'),
        );

        // Source soft-deleted
        $source->refresh();
        $this->assertTrue((bool) $source->is_deleted, 'source should be soft-deleted after merge.');
    }

    public function test_dedupes_organizations_peoples_when_target_already_has_the_same_person(): void
    {
        $source = $this->seedOrganization('ACME Source');
        $target = $this->seedOrganization('ACME Target');

        // Pretend peoples_id=1234 is on both
        OrganizationPeople::create([
            'organizations_id' => $source->id,
            'peoples_id' => 1234,
            'created_at' => Carbon::now(),
        ]);
        OrganizationPeople::create([
            'organizations_id' => $target->id,
            'peoples_id' => 1234,
            'created_at' => Carbon::now(),
        ]);
        // peoples_id=5678 only on source — should rebind
        OrganizationPeople::create([
            'organizations_id' => $source->id,
            'peoples_id' => 5678,
            'created_at' => Carbon::now(),
        ]);

        new MergeOrganizationsAction(
            source: $source,
            target: $target,
            user: static::$cachedUser,
        )->execute();

        // Source has no rows left
        $this->assertSame(
            0,
            OrganizationPeople::query()->where('organizations_id', $source->id)->count(),
            'source pivot rows should be either rebound or deleted.',
        );
        // Target has both peoples
        $this->assertSame(
            2,
            OrganizationPeople::query()->where('organizations_id', $target->id)->count(),
            'target should have both peoples (one pre-existing, one rebound from source).',
        );
    }

    public function test_rejects_cross_tenant_merge(): void
    {
        $source = $this->seedOrganization('ACME Source');

        // Build a fake target in a different tenant
        $target = $this->seedOrganization('ACME Target');
        $target->companies_id = $this->company->getId() + 9999;
        $target->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/across tenants/');

        new MergeOrganizationsAction(
            source: $source,
            target: $target,
            user: static::$cachedUser,
        )->execute();
    }

    public function test_rejects_self_merge_and_deleted_source(): void
    {
        $sameOrg = $this->seedOrganization('ACME');

        $threwSelf = false;

        try {
            new MergeOrganizationsAction(
                source: $sameOrg,
                target: $sameOrg,
                user: static::$cachedUser,
            )->execute();
        } catch (RuntimeException) {
            $threwSelf = true;
        }
        $this->assertTrue($threwSelf, 'merge into self should be rejected.');

        // Deleted source guard
        $deletedSource = $this->seedOrganization('ACME Deleted');
        $deletedSource->is_deleted = true;
        $deletedSource->save();
        $target = $this->seedOrganization('ACME Target');

        $threwDeleted = false;

        try {
            new MergeOrganizationsAction(
                source: $deletedSource,
                target: $target,
                user: static::$cachedUser,
            )->execute();
        } catch (RuntimeException) {
            $threwDeleted = true;
        }
        $this->assertTrue($threwDeleted, 'merging an already-deleted source should be rejected.');
    }

    // ─────────────────────── fixtures ───────────────────────

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

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function minimalAccountingRow(array $extra): array
    {
        return $extra + [
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'uuid' => (string) Str::uuid(),
            'currency' => 'USD',
            'fx_rate_to_base' => 1.0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function billDefaults(): array
    {
        return [
            'document_status' => 'draft',
            'subtotal_native' => 100,
            'tax_native' => 0,
            'discount_native' => 0,
            'total_native' => 100,
            'paid_native' => 0,
            'balance_due_native' => 100,
            'subtotal_base' => 100,
            'tax_base' => 0,
            'discount_base' => 0,
            'total_base' => 100,
            'paid_base' => 0,
            'balance_due_base' => 100,
            'source' => 'kanvas',
            'origin' => 'kanvas',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function expenseDefaults(): array
    {
        return [
            'status' => 'draft',
            'expense_date' => Carbon::today(),
            'paid_by' => 'company_card',
            'reimbursement_status' => 'not_applicable',
            'subtotal_native' => 50,
            'tax_native' => 0,
            'total_native' => 50,
            'subtotal_base' => 50,
            'tax_base' => 0,
            'total_base' => 50,
            'source' => 'kanvas',
            'origin' => 'kanvas',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceDefaults(): array
    {
        return [
            'document_type' => 'invoice',
            'document_status' => 'draft',
            'tax_calculation_mode' => 'exclusive',
            'delivery_status' => 'not_applicable',
            'net_terms_days' => 30,
            'subtotal_native' => 200,
            'tax_native' => 0,
            'discount_native' => 0,
            'total_native' => 200,
            'paid_native' => 0,
            'balance_due_native' => 200,
            'subtotal_base' => 200,
            'tax_base' => 0,
            'discount_base' => 0,
            'total_base' => 200,
            'paid_base' => 0,
            'balance_due_base' => 200,
            'source' => 'kanvas',
            'origin' => 'kanvas',
        ];
    }
}
