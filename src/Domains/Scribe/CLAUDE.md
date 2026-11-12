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
- **An organization's tax id is the `organizations.tax_id` column** (+ `tax_id_type`, `TaxIdTypeEnum`, Stripe-style
  codes, inferred on save from the company's country when not given), one country-neutral id: RNC, NIT, RUC, RFC, EIN.
  Never read or write the old `tax_id` / `rnc` / `ein` custom fields; `guild:backfill-organization-tax-id` copied them once.
- **Payments live in Souk** (`Souk.Payments` polymorphic via `payable_type`/`payable_id`). Scribe's `Invoice` and `Bill` implement `Baka\Contracts\PayableInterface` (extracted in PR -1). `accounting.invoice_payment_allocations` maps one Souk payment to N invoices/bills.
- **Stripe lives in `Connectors/Stripe/`, ALWAYS.** No in-Scribe Stripe handlers, period. When Scribe needs Stripe (tenant AR via Stripe Billing), the work goes in `Connectors/Stripe/` and calls Scribe's public `*FromExternal` Actions.
- **Items (`accounting.items`) live in Scribe** with a nullable `inventory_variant_id` FK to `inventory.variants.id`. Variant — not Product, because you sell the variant. Pure-service items have `inventory_variant_id=NULL`. Each domain owns its own fields; no auto-sync in either direction except one-time creation observers.
- **Document PDFs live in `Scribe/Documents/`.** `DocumentPdfService` renders an Invoice **or** a Quote
  from the `scribe-invoice` / `scribe-quote` Twig row (company, then app, then platform; same lookup as
  `scribe-ecf-invoice` and `scribe-sow`), falling back to the one packaged layout
  (`resources/views/pdf/scribe-document.twig`), and attaches the file to the document (`invoice_pdf` /
  `quote_pdf` field names). `ScribeDocumentTemplateSeeder::forApp($appsId)` writes both rows for an app so
  the tenant edits them in the template UI; the two names share one file so an invoice restyle never
  touches quotes. `Scribe\Documents\Enums\ConfigurationEnum::{INVOICE,QUOTE}_PDF_TEMPLATE` naming any
  other stored template wins over the rows.
  One service, not two: the documents differ only in title, number field, due-vs-valid-until and
  amount-paid, and two layouts would drift. **Rendering is not sending.** Sending lives in
  `Scribe/Delivery/`: a document reaches a customer only as an ActionEngine engagement on the document
  itself (`view-invoice`, `view-quote`, `sign-sow`; aliases `scribe_invoice` / `scribe_quote`), through
  `SendInvoiceAction` / `SendQuoteAction(deliverTo:)` → `SendDocumentEngagementAction`, whose engagement
  step is `OpenDocumentEngagementAction` — the same one `get_document_link` uses, so a hand-shared link and
  a later send are one engagement. Recipients come from `BillingContactResolverService` (named person →
  quote contact → every person linked to the customer with the `billing-contact` people tag → billing
  email); naming a person tags and links them. Never the old `billing_contact_people_id` custom field
  (`scribe:migrate-billing-contacts` moved it once). An agent reaches a customer with an invoice, quote or SOW only
  through `send_invoice` / `send_quote` (a tracked engagement: opens, signatures, payments on record). The AP and AR
  agents hold `send_person_email` for plain correspondence (a vendor statement request, a customer's question) only
  while a real person is asking (`personAsking()`, both read inbound email); it cannot attach a file, and the only
  document reference it can carry is the `get_document_link` engagement link, so a document never leaves untracked.
  Never grant them a WhatsApp or attachment-capable send. A person may still generate the PDF and send it by hand. Never use an e-CF or bank provider's own email (untracked). An e-CF invoice is not sent before
  DGII accepted it. The public page renders `Engagement.document_page` (`toTemplateData()`), never the
  row; the engagement's `message.data` also carries `document_number`, `name` and `html` (`DocumentHtmlService`:
  the string the PDF is printed from, the SOW layout for `sign-sow`, the legal e-CF representation once DGII
  accepted and no `html` before), rewritten on every open so a reused link shows the document as it is now;
  `html` is for display, the actions (accept, sign, pay, QR) stay on `document_page`; customer answers come back through `respondToDocumentEngagement` → `RecordDocumentResponseAction`
  (accept = `AcceptQuoteAction`, decline = `RejectQuoteAction`), and paid closes the thread from
  `MarkInvoicePaidAction`. Dunning is the follow-up engine's Invoice executor
  (`Intelligence/FollowUp/Actions/FollowUpInvoiceAction`, company setting `scribe_dunning_schedule`).
  Per tenant: `scribe:setup-document-actions {app_id} {company_id?}`. Links shorten through the
  company's short.io integration, else the app's; the page base URL is the company's
  `link_page_base_url`, else the app's `NEW_ACTION_PAGE_V4` (`ShortIoConfigurationService`).
- **Every primary document exposes `files`.** Bill, Invoice, Quote, Expense, SalesReceipt and Payment all
  carry Kanvas Filesystem attachments (the source invoice PDF an AP/AR agent was handed, a rendered
  `invoice_pdf` / `quote_pdf`, a receipt, a remittance) and all expose them as `files: [Filesystem!]!
  @cacheRedis @paginate(builder: FilesystemQuery@getFileByGraphType)`. Third-party files are **never**
  stashed as a custom-field URL — that was the pre-2026-09 shape and only `ApprovalCustomFieldEnum`'s
  two legacy cases still read it. Wiring a new document for files means all four of: `HasLightHouseCache`
  on the model, `getGraphTypeName()` returning the GraphQL type name, `#[ObservedBy([ClearsLightHouseCacheObserver::class])]`
  (one shared observer for the whole domain — do not add a per-model copy), and the schema field. Miss the
  trait and `@cacheRedis` serves the pre-upload payload forever. Guarded by
  `ScribeGraphQLSurfaceTest::test_every_scribe_document_exposing_files_is_wired_for_cache_invalidation`.
  `JournalEntry` is deliberately out — it extends `EloquentModel` directly, not the Scribe `BaseModel`,
  so it has no `HasFilesystemTrait`; `PurchaseOrder` and `BankTransaction` have no GraphQL type at all yet.
- **Regional compliance** (NCF for DR, CFDI for MX, NFE for BR, …) lives in a single `regional_compliance` JSON column on invoices/bills/quotes/sales_receipts, validated by per-country validator services in `Scribe/Regional/Validators/{Country}/{Code}Validator`. NOT custom fields (those are tenant-choice extensions); NOT dedicated DR-only columns (schema bloat).
- **Electronic invoicing state is NOT in that JSON.** `regional_compliance.do_ecf` holds only the pre-issuance
  choices (`EcfCompliance::draftSettings()`: type, export, purchase order, withholding, foreign payment). From
  the moment a number is allocated, the e-CF is a row in `fiscal_documents` (country-neutral; plan:
  `docs/accounting/fiscal-documents-table-plan.md`), with `fiscal_document_submissions` (one per send, inserted
  before the call, wire payload + raw response stored as files with sha256), `fiscal_document_events` (every
  inbound answer, deduped, applied under the row lock), `fiscal_document_relations` (notes → modified document)
  and `fiscal_number_ranges` (+ ANECF annulments). Write only through `CreateFiscalDocumentAction`,
  `RecordFiscalSubmissionAction` and `ApplyFiscalAnswerAction`; read through `EcfCompliance::read()/current()`
  or `ReceivedEcfCompliance`. The provider `systemRef` is the send attempt's `system_ref` (16 uppercase
  alphanumerics, never the invoice's uuid: WebPOS answers a repeated systemRef with the status it already holds,
  so a resend after a reusable rejection needs its own, and it forwards the value as DGII's 20-character
  `NumeroFacturaInterna`, which is why a 36-character uuid earned observation 1323 on every e-CF; attempts from
  before the column still answer to their `uuid`), jobs take the fiscal row and build the provider for the row's frozen
  provider and environment, and callbacks resolve to that exact row — an answer about a superseded number never
  touches the document. Rows are never deleted and freeze once they may have reached the authority. A DGII
  rejection burns the number unless `secuenciaUtilizada` is explicitly false. Deploy: `scribe:dr-fiscal-preflight`
  → migrate → `scribe:dr-fiscal-backfill` (test data only); `scribe:fiscal-resolve-stuck-submissions` runs every
  10 minutes.
- **WebPOS mapping facts learned on the test host (2026-11-10), each pinned by a mapper test.** `fedo.totales.totalIsrRetenido`
  must be sent or DGII observes 11174 (WebPOS sums item ITBIS withholding into the header, not ISR). An `OtraMoneda`
  `addInfo` group makes WebPOS drop the document with `received: false` and no message; it builds that block itself
  from `currency` + `currencyRate`, so only the IdDoc payment lines go. DGII's `TipoCuentaPago` is CT/AH/OT
  (`DrEcfValidator::PAYMENT_ACCOUNT_TYPES`, observation 1144 otherwise). A provider refusal keeps the raw answer as
  the attempt's response artifact; read it before guessing.
- **A country's rules live in `ElectronicInvoicing/Regimes/{Country}/`, never in shared code or a table.** Shared
  code (`EcfIssuanceService`, the jobs, `ConfigureEcfRangeAction`, readiness, the agent tools) resolves a
  `FiscalRegimeInterface` and asks it; it never names a country's types, payload, authority or currency. A
  company's regime is its provider's `country()`, else its own country (`FiscalRegimes::forCompany()`); a fiscal
  row's is its `country` column (`$fiscal->regime()`). The regime owns: document types (an enum implementing
  `FiscalDocumentTypeInterface`, DR: `EcfTypeEnum`, because a type carries rules, so a new one ships with code),
  which type a sale or note becomes, its purchase types (empty where there are none), pre-number checks
  (`assertMayIssue`, DR: issuer RNC + DGII registration for E31), numbering (`FiscalNumberingInterface`;
  `AuthorizedRangeNumbering` is shared for range countries, DR and Colombia), the wire payload
  (`FiscalPayloadInterface`, DR: `EcfDocument`) and its validation, the fiscal row's attributes, the seal in the
  authority's stamp URL, and its legal invoice PDF. DR builders, DTOs, the ITBIS enum and the QR helper live in
  `Regimes/Dr/` too. A provider receives the payload its regime built and narrows it (`EcfDocument::narrow()`).
- **Adding a country:** `Regimes/{Country}/` with the regime, its type enum, its payload and its received-document
  DTO (implementing `ReceivedFiscalDocumentInterface` / `ReceivedFiscalLineInterface`); register it in
  `config/scribe.php` `fiscal_regimes`; add its `DocumentTypeEnum` `ecf_{code}` cases for range numbering; ship a
  provider whose `country()` names it. A provider serving several countries (Alanube) gets one class and config key
  per country, sharing its client. Nothing else changes: issuing, vendor invoices received into bills
  (`CreateBillFromReceivedEcfAction`, source `e_invoice` in every country), the draft block
  (`regional_compliance.{cc}_ecf`, `do_ecf` in the DR, from `EcfCompliance::key()`), the GL tax accounts
  (`salesTaxAccountSubType()` / `inputTaxAccountSubType()`), the official exchange rate, readiness, setup and the
  agent tools all ask the regime. Onboarding requirements stay per country in `global.json` (`countries`).
  `SecondCountryRegimeTest` runs issuing, notes, receiving and the draft key on a fake country and must stay green.
  A company's country for setup is its regime first, so one connected to a DR provider with no country set is still
  Dominican. DR by nature: the legacy data commands `scribe:dr-fiscal-preflight` / `scribe:dr-fiscal-backfill`, the
  DR chart of accounts (a per-country `match` in `ChartOfAccountsSeederService`) and paper NCFs on PDF bills
  (`Regional/Validators/Dr`). Support loads a range with `kanvas-scribe:ecf-sequence … --country=XX` when the
  company has no provider or country yet. A row stores only the type code; read it through its own `country`
  (`fiscalDocumentType()` / `documentTypeLabel()` from `HasFiscalDocumentType`). Our lifecycle
  (`FiscalDocumentStatusEnum`) is one enum for every country; the authority's own status is the raw
  `authority_status` string. Pure reference catalogs with no rules attached (Mexico's SAT `c_UsoCFDI`, product
  codes) are the exception: seeded tables per country.
- **Loading a range asks the provider first.** Numbers of a DGII range can be used outside Kanvas (a manual test,
  another POS), so `ConfigureEcfRangeAction` asks a provider implementing `ChecksIssuedNumbersInterface` which
  numbers it already holds and starts issuing after them; the range itself is stored as authorized. A lookup by
  number is only ours when it carries our attempt's `systemRef`.

## Setting up a tenant (AP/AR agents + DR e-CF)

All setup goes through company onboarding and the agent tools; there is no setup API and nothing the end user
runs. Picking AP/AR in onboarding (`database/seeders/data/onboarding/global.json`, `setup: "accounting"`) runs
`SetUpAccountingAgentAction` (ledger, periods, approval + e-CF policies, inbox, agent mailboxes, document pages,
dunning, SOW) and `completeOnboarding` opens a setup plan on Ginny whose tasks close as their readiness key turns
ready (`SyncAccountingSetupPlanAction`). Ginny (`CompanyBrainAgent`, once the company has an AP/AR agent) finishes
the rest in conversation: `check_accounting_setup` (the `AccountingReadinessService` answer the agents' prompt line
also reads), `run_accounting_setup` (re-run, change the approver), `configure_ecf_range` / `list_ecf_ranges` (DGII
ranges, Dominican companies) and `set_reminder_cadence`; the AP/AR agents hold the check and range tools for
renewals (`AppliesAccountingReadiness::accountingSetupTools()`). The e-CF provider is the onboarding `ecf_provider`
requirement (connect `webpos` with its `rnc`, or `alanube`); an agent never takes provider credentials in chat. A new
setup thing is a readiness key + an onboarding step + an agent tool, never a command or a separate screen; the
`kanvas-scribe:setup-accounting` / `kanvas-scribe:ecf-sequence` commands are for support only.

Rules: the accounting inbox needs a verified Mailgun domain on the company (the setup reports `inbox: failed`
otherwise; everything else still runs). DR issuance refuses without the issuer RNC (`scribe-ecf-issuer-rnc`, written
when the provider is connected). A range is a company/DGII fact, not a provider setting: switching WebPOS → Alanube keeps it. Changing
`global.json` reaches the database through `kanvas:onboarding:seed-sections` (run it on deploy).

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
