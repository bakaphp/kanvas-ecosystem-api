# Scribe — Kanvas Accounting Domain

**Scribe = the bookkeeping / accounting domain.** Namespace `Kanvas\Scribe\`, DB connection `accounting`.

The name follows the Kanvas convention of evocative one-word domain names that capture the essence of what the domain does:
- **Guild** (CRM — band of allies / trade association)
- **Souk** (commerce — marketplace)
- **NervousSystem** (agent runtime — body's signaling network)
- **Scribe** (accounting — ancient record-keeper of value and obligation)

A scribe historically recorded debts owed, grain stored, taxes due. That's exactly what this domain does: GL spine + sub-ledgers + master data + per-tenant Chart of Accounts, mirrored from the canonical accounting model used by QBO / NetSuite / Xero.

**The CFO agent is NOT named Scribe.** Scribe holds the books; the CFO agent reads from them and advises. The split between data layer (Scribe) and intelligence layer (NervousSystem CFO agent) is intentional — same pattern as Guild data vs Intelligence agents.

## Canonical reference

Full architecture + schema + phase plan + worked examples lives at:

- [`docs/accounting/cfo-agent-plan.md`](../../../docs/accounting/cfo-agent-plan.md) — the single load-bearing doc. **Always read this before touching anything in `src/Domains/Scribe/`.** Covers domain model (GL spine + sub-ledgers + master data), cross-domain integration (Guild for Customer/Vendor, Souk for Payment morph, Stripe via Connectors), table schemas, JE composer pattern, every state machine, every invariant, and the Cut B execution slice.

Key sections you'll touch most:
- **§3 + §3.5** — folder layout + canonical accounting model + sub-ledger → GL auto-posting rule
- **§4.1–4.7** — cross-domain integration (Guild Billable/Payee interfaces in `Baka\Contracts\`, Souk.Payments polymorphic reuse, Stripe lives ONLY in `Connectors/Stripe/`)
- **§5** — every table schema with column-level detail
- **§6.0** — Cut B PR sequence (PR -1 through PR 8)
- **§7** — GL invariants (JE balance, account-currency match, period-close gate, etc.) — these are non-negotiable
- **§10 + §11** — worked examples for every sub-ledger flow

## Naming conventions specific to this tree

- **Models** all extend `Kanvas\Scribe\Models\BaseModel` which sets `protected $connection = 'accounting'`.
- **DB tables** all live in the `accounting` schema and follow `accounting.{plural_entity_name}` naming (e.g., `accounting.invoices`, `accounting.journal_entries`, `accounting.invoice_payment_allocations`).
- **Migrations** land in `database/migrations/Scribe/` (the folder name follows the domain namespace, not the DB name).
- **GraphQL schemas** land in `graphql/schemas/Scribe/` per the convention enforced by `graphql/schemas/CLAUDE.md`.
- **JE composer services** are per-sub-ledger: `InvoiceJournalEntryComposer`, `BillJournalEntryComposer`, `ExpenseJournalEntryComposer`, etc. They live in their sub-ledger folder (`Scribe/Invoices/Services/`, `Scribe/Bills/Services/`, etc.). Every sub-ledger Action that creates/issues/voids/refunds a transaction calls `PostJournalEntryAction($composer->compose($entity))` at the end. No exceptions — enforced by coverage test.
- **State machines** are per-entity (`InvoiceStateMachine`, `BillStateMachine`, `ExpenseStateMachine`) and gate every status transition. Direct `$invoice->document_status = 'paid'; save()` is banned via observer.
- **Two-axis status**: every transaction has a `document_status` (the document state — draft/issued/sent/paid/voided) AND a `collection_state` (the collections lens — current/overdue/disputed/uncollectible) for AR/AP entities. Computed separately, refreshed by the daily aging job.

## Conventions specific to GL invariants

These are the rules that keep the books coherent. Read §7 of the plan doc for the full list — the headline ones:

1. **Every sub-ledger Action posts a balanced JE** via `PostJournalEntryAction`. `SUM(debit_base) == SUM(credit_base)` per JE — validator + observer enforce.
2. **System accounts are undeletable.** AR, AP, Cash, Sales Tax Payable, Retained Earnings, Due to Employees — marked `is_system=true` on `accounts`. Delete attempts throw.
3. **Period close gates posting.** `PostJournalEntryAction` checks `fiscal_period.status='open'` for the posted_at date. Soft-closed requires `accounting.post_to_closed_period` Bouncer ability. Hard-closed rejects.
4. **Externally-imported JEs are authoritative.** When QBO/NetSuite/Xero ship their own JournalEntry rows, the connector uses `CreateOrUpdateJournalEntryFromExternalAction` directly — never re-derives from sub-ledger Actions. The source's GL is the truth.
5. **JE-line currency matches account currency when set.** Foreign-currency bank accounts post lines in their currency, never the company base.
6. **Billable + Vendor snapshots are immutable post-issue.** Customer renames/address changes don't propagate to historical invoices — the snapshot fields freeze at Issue. `AmendInvoiceAction` is the only post-issue mutator and emits an `accounting.invoice.amended` diff event.
7. **Reversals preserve history.** Payment reversals flip `invoice_payment_allocations.status='reversed'` — never row-delete. JE reversals are mirror JEs (`is_reversal_of` set), never row-edit.
8. **Money precision: `DECIMAL(18,4)`. FX precision: `DECIMAL(20,10)`.** Half-up rounding at display only — stored values keep full 4-decimal precision.

## Cross-domain rules (the easiest ones to violate)

- **Customers + Vendors live in Guild** (`Guild.Organizations.Organization` + `Guild.Customers.People`). Both implement `Baka\Contracts\BillableInterface` + `Baka\Contracts\PayeeInterface`. Scribe consumes only the interfaces — no Scribe-naming inside Guild.
- **Payments live in Souk** (`Souk.Payments` polymorphic via `payable_type`/`payable_id`). Scribe's `Invoice` and `Bill` implement `Baka\Contracts\PayableInterface` (extracted in PR -1). `accounting.invoice_payment_allocations` maps one Souk payment to N invoices/bills.
- **Stripe lives in `Connectors/Stripe/`, ALWAYS.** No in-Scribe Stripe handlers, period. When Scribe needs Stripe (tenant AR via Stripe Billing), the work goes in `Connectors/Stripe/` and calls Scribe's public `*FromExternal` Actions.
- **Items (`accounting.items`) live in Scribe** with a nullable `inventory_variant_id` FK to `inventory.variants.id`. Variant — not Product, because you sell the variant. Pure-service items have `inventory_variant_id=NULL`. Each domain owns its own fields; no auto-sync in either direction except one-time creation observers.
- **Document PDFs live in `Scribe/Documents/`.** `DocumentPdfService` renders an Invoice **or** a Quote
  through one shared Blade layout (`resources/views/pdf/scribe-document.blade.php`) and attaches the file
  to the document (`invoice_pdf` / `quote_pdf` field names). A tenant overrides the layout by pointing
  `Scribe\Documents\Enums\ConfigurationEnum::{INVOICE,QUOTE}_PDF_TEMPLATE` at a stored template name —
  that path goes through `RenderTemplateAction`, so the template is Blade with the same view data.
  One service, not two: the documents differ only in title, number field, due-vs-valid-until and
  amount-paid, and two layouts would drift. **Rendering is not sending** — nothing emails a document; the
  outbound flow (PR 7) stays out of scope on purpose.
- **Regional compliance** (NCF for DR, CFDI for MX, NFE for BR, …) lives in a single `regional_compliance` JSON column on invoices/bills/quotes/sales_receipts, validated by per-country validator services in `Scribe/Regional/Validators/{Country}/{Code}Validator`. NOT custom fields (those are tenant-choice extensions); NOT dedicated DR-only columns (schema bloat).

## What's NOT in Scribe (and why)

- **Inventory accounting** (COGS valuation, FIFO/LIFO/avg, stock movements) — deferred. Items still exist (as a name list with GL account mapping) but inventory valuation is its own multi-PR module added when a real customer asks.
- **Dimensions** (Classes / Departments / Locations / Projects) — schema-reserved on `journal_entry_lines` as nullable columns, no management UI in v1.
- **Multi-entity consolidation** (intercompany eliminations, parent-currency conversion at consolidation rate) — Phase 4. Per-entity views ship in v1 because `companies_id` already partitions everything.
- **Fixed assets + depreciation**, **time tracking**, **payroll**, **1099/W2 prep**, **budgets**, **projects/jobs** — all deferred until a real customer asks.

## When to touch this tree

- ✅ Adding a sub-ledger Action / state transition → goes in the matching sub-ledger folder
- ✅ Adding a JE composer for a new transaction type → goes alongside the sub-ledger
- ✅ Adding a new region's regulatory compliance shape → `Scribe/Regional/Validators/{Country}/`
- ✅ Adding a new report → `Scribe/Reports/Services/`
- ✅ Adding a Capability skill for the CFO agent → lives in NervousSystem, NOT Scribe (Scribe is the data layer; agent skills are the consumer)
- ❌ Adding Stripe-anything → goes in `Connectors/Stripe/`
- ❌ Adding Customer or Vendor identity fields → goes in Guild
- ❌ Storing payment transactions → reuse `Souk.Payments` (polymorphic)
- ❌ Tenant-choice extensions to invoices → Kanvas custom fields, NOT `regional_compliance`

## PR sequence in flight

Per §6.0 of the plan doc. Cut B is what we're executing now:

- **PR -1** Souk prerequisite refactor (extract `PayableInterface` to `Baka\Contracts`, rename `Payments::order()` → `payable()`, refactor `PayableTrait` off Order-specific column). MUST merge before Phase 1.
- **PR 0** Scribe domain bootstrap (PSR-4 register, `accounting` DB connection, `Scribe/Models/BaseModel.php`, empty migration + GraphQL folders).
- **PR 1** Master data + GL spine (Accounts, JournalEntries, FiscalPeriods, Items, TaxCodes, PaymentTerms, DocumentSequences, FxRates).
- **PR 2** Invoices sub-ledger.
- **PR 3** Quotes.
- **PR 4** Sales Receipts.
- **PR 5** Expenses + Approval Queue + Bank Accounts stub.
- **PR 6** ADM Cloud connector (poll-only per Swagger).
- **PR 7** Send invoice flow.
- **PR 8** Read agent + reports + dashboard surface.

Full detail: `docs/accounting/cfo-agent-plan.md` §6.0.
