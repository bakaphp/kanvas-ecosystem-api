# NZXT — client-specific document parsers

Loads when work touches `src/Domains/Connectors/Nzxt/`.

Holds business logic tied to NZXT's own exact document formats — not generic Kanvas/Scribe behavior, and not an integration with an external API (contrast with Acumatica/Gmail/GoogleSheets connectors, which talk to a third-party service). This exists as its own module so client-specific parsing never gets baked into the shared domain layer (`src/Domains/Scribe/`, used by every Kanvas tenant).

## What's here

- **`Services/CreditRequestFormParserService.php`** — parses NZXT Sales's "Credit Request Form" (CNR) Excel template (fixed label/column layout) into the fields `create_ar_credit_memo` needs. Implements `Kanvas\Scribe\Invoices\Contracts\CreditRequestFormParserInterface`.

## Adding another client's credit-request format

`ExtractCreditRequestFormTool` (`src/Domains/Intelligence/Agents/Neuron/Tools/Accounting/`) never depends on this class directly — it calls `Kanvas\Scribe\Invoices\Services\CreditRequestFormParserFactory::forApp($app)`, which reads the tenant's key and builds whichever class `config('scribe.credit_request_form_parsers')` maps it to (same shape as the e-CF providers in that file). Neither the tool nor Scribe names a parser class; `config/scribe.php` is the only place a parser is joined to them, and a parser not listed there is unreachable. **This parser is not listed there yet**: `credit_request_form_parsers` ships empty, so step 2 below still has to be done for it before any app's key resolves. When a different client needs its own credit-request document parsed:

1. Build `Connectors/{Client}/Services/CreditRequestFormParserService.php` implementing `CreditRequestFormParserInterface`.
2. Add one line to `credit_request_form_parsers` in `config/scribe.php` (e.g. `'other_client' => OtherClientCreditRequestFormParserService::class`).
3. Set that tenant's app config `credit-request-form-client` (`ConfigurationEnum::CREDIT_REQUEST_FORM_CLIENT`) to that key — an app with no value set has no parser and `extract_credit_request_form` tells the agent to use `draft_credit_note` instead, so every app that reads a form must set its key explicitly.

Never add a second client's label/layout knowledge into this NZXT-specific class — that's exactly the coupling this module exists to avoid.
