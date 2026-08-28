# NZXT — client-specific document parsers

Loads when work touches `src/Domains/Connectors/Nzxt/`.

Holds business logic tied to NZXT's own exact document formats — not generic Kanvas/Scribe behavior, and not an integration with an external API (contrast with Acumatica/Gmail/GoogleSheets connectors, which talk to a third-party service). This exists as its own module so client-specific parsing never gets baked into the shared domain layer (`src/Domains/Scribe/`, used by every Kanvas tenant).

## What's here

- **`Services/CreditRequestFormParserService.php`** — parses NZXT Sales's "Credit Request Form" (CNR) Excel template (fixed label/column layout) into the fields `create_ar_credit_memo` needs. Implements `Kanvas\Scribe\Invoices\Contracts\CreditRequestFormParserInterface`.

## Adding another client's credit-request format

`ExtractCreditRequestFormTool` (`src/Domains/Intelligence/Agents/Neuron/Tools/Accounting/`) depends only on `CreditRequestFormParserInterface`, never on this class directly. When a different client needs its own credit-request document parsed:

1. Build `Connectors/{Client}/Services/CreditRequestFormParserService.php` implementing the same interface.
2. Resolve the right one per tenant — bind the interface to the client's implementation (e.g. via `app()->bind()` in a tenant-aware provider, or extend `ExtractCreditRequestFormTool::parser()` to pick by app/company) rather than editing NZXT's parser to branch on client.

Never add a second client's label/layout knowledge into this NZXT-specific class — that's exactly the coupling this module exists to avoid.
