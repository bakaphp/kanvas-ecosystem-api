# NZXT — client-specific document parsers

Loads when work touches `src/Domains/Connectors/Nzxt/`.

Holds business logic tied to NZXT's own exact document formats — not generic Kanvas/Scribe behavior, and not an integration with an external API (contrast with Acumatica/Gmail/GoogleSheets connectors, which talk to a third-party service). This exists as its own module so client-specific parsing never gets baked into the shared domain layer (`src/Domains/Scribe/`, used by every Kanvas tenant).

## What's here

- **`Services/CreditRequestFormParserService.php`** — parses NZXT Sales's "Credit Request Form" (CNR) Excel template (fixed label/column layout) into the fields `create_ar_credit_memo` needs. Implements `Kanvas\Scribe\Invoices\Contracts\CreditRequestFormParserInterface`.

## Adding another client's credit-request format

`ExtractCreditRequestFormTool` (`src/Domains/Intelligence/Agents/Neuron/Tools/Accounting/`) never depends on this class directly — it calls `Kanvas\Scribe\Invoices\Services\CreditRequestFormParserFactory::forApp($app)`, which picks the right parser per tenant (a plain `match`, same shape as `AgentRuntimeProviderFactory` — no DI container binding). When a different client needs its own credit-request document parsed:

1. Add a case to `Kanvas\Scribe\Invoices\Enums\CreditRequestFormClientEnum` (e.g. `OTHER_CLIENT = 'other_client'`).
2. Build `Connectors/{Client}/Services/CreditRequestFormParserService.php` implementing `CreditRequestFormParserInterface`.
3. Add a matching arm to `CreditRequestFormParserFactory::forClient()`.
4. Set that tenant's app config `credit-request-form-client` (`ConfigurationEnum::CREDIT_REQUEST_FORM_CLIENT`) to the new enum value — NZXT's apps stay on the default and are unaffected.

Never add a second client's label/layout knowledge into this NZXT-specific class — that's exactly the coupling this module exists to avoid.
