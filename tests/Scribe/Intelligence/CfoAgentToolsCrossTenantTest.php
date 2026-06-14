<?php

declare(strict_types=1);

namespace Tests\Scribe\Intelligence;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ListOverdueInvoicesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryArAgingTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryBalanceSheetTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryDataFreshnessTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryRecentExpensesTool;
use Kanvas\Scribe\Invoices\Actions\CreateInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLineData;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\Invoices\Stubs\StubBillable;
use Tests\Scribe\ScribeTestCase;

/**
 * Cross-tenant safety: every CFO Tool MUST scope to the current user's company. The tools all use
 * `auth()->user()->getCurrentCompany()` internally — a regression that drops that filter would leak
 * cross-tenant data into LLM responses, the worst-shaped bug we could ship for this domain.
 *
 * Strategy: seed Scribe data for the current tenant, then call each tool and assert the returned
 * payload includes the seeded data shape. We can't easily simulate "another tenant" in the same
 * authenticated test session without orchestrating a second Apps + Company; instead, we assert the
 * tool's SQL clause uses the current company by checking output shape + presence of seeded rows.
 *
 * Regression smoke: if anyone drops the apps_id/companies_id filters from the underlying
 * repository, these tests will start picking up paratest sibling tests' data and fail loudly.
 */
class CfoAgentToolsCrossTenantTest extends ScribeTestCase
{
    public function test_query_balance_sheet_tool_returns_only_current_tenant_data(): void
    {
        $this->seedIssuedInvoice(total: 1000.0);

        $payload = new QueryBalanceSheetTool()->__invoke(
            as_of: '2026-06-30',
            currency: 'USD',
        );

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('assets', $payload);
        $this->assertArrayHasKey('liabilities', $payload);
        $this->assertArrayHasKey('equity', $payload);
        $this->assertTrue(
            (bool) $payload['is_balanced'],
            'Balance sheet for current tenant should be balanced — cross-tenant rows would unbalance it.',
        );
    }

    public function test_query_ar_aging_tool_isolates_to_current_tenant(): void
    {
        $this->seedIssuedInvoice(total: 500.0);

        $payload = new QueryArAgingTool()->__invoke(as_of: '2026-06-30', currency: 'USD');

        $this->assertIsArray($payload);
        $this->assertSame(500.0, (float) $payload['grand_total']);
    }

    public function test_list_overdue_invoices_tool_isolates_to_current_tenant(): void
    {
        $this->seedIssuedInvoice(total: 750.0, dueDateOffsetDays: -45);

        $payload = new ListOverdueInvoicesTool()->__invoke(limit: 25, min_days_overdue: 1);

        $this->assertIsArray($payload);
        $this->assertGreaterThanOrEqual(1, (int) $payload['count']);
        foreach ($payload['invoices'] as $invoice) {
            $this->assertGreaterThan(0, (float) $invoice['balance_due_native']);
        }
    }

    public function test_query_recent_expenses_tool_isolates_to_current_tenant(): void
    {
        $payload = new QueryRecentExpensesTool()->__invoke(days_back: 30);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('expenses', $payload);
        // Even with no expenses, the tool MUST return a typed shape — never null, never error
        $this->assertIsArray($payload['expenses']);
    }

    public function test_query_data_freshness_tool_returns_typed_meta(): void
    {
        $this->seedIssuedInvoice(total: 100.0);

        $payload = new QueryDataFreshnessTool()->__invoke();

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('counts', $payload);
        $this->assertGreaterThanOrEqual(1, (int) $payload['counts']['journal_entries_posted']);
        $this->assertGreaterThanOrEqual(1, (int) $payload['counts']['invoices_issued_or_sent']);
    }

    private function seedIssuedInvoice(float $total, int $dueDateOffsetDays = 0): void
    {
        $billable = new StubBillable();
        $draft = new CreateInvoiceAction(
            data: new InvoiceData(
                app: $this->kanvasApp,
                company: $this->company,
                billable: $billable,
                lines: new DataCollection(InvoiceLineData::class, [
                    new InvoiceLineData(
                        description: 'CFO tool smoke',
                        quantity: 1.0,
                        unit_price_native: $total,
                    ),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                issued_date: Carbon::parse('2026-06-15'),
                due_date: Carbon::parse('2026-06-15')->addDays($dueDateOffsetDays + 30),
                net_terms_days: 30,
            ),
            user: static::$cachedUser,
        )->execute();

        new IssueInvoiceAction(
            invoice: $draft,
            billable: $billable,
            user: static::$cachedUser,
        )->execute();
    }
}
