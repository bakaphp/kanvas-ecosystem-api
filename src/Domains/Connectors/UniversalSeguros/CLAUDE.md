# Universal Seguros (Unit ServicePlattform) — Auto Insurance Connector

Integration with **Universal Seguros' "Unit ServicePlattform" Auto REST API**, consumed by the
Movipass *aliado*. Lets the app quote, collect documents, take payment, emit, and read back
auto-insurance policies (products: Para Tu Auto, Por Lo Que Conduces, Por Si Chocas, Por Si
Pierdes Tu Auto, Para Tu Seguro de Ley).

> ⚠️ This is **not** the `Movipass` connector (that one models Movipass's own roadside/mechanic
> product) and **not** `UniversalAssistance` (travel insurance, different brand). Different concern,
> separate folder.

## Source of truth

- Spec lived in `MOVIPASS_AUTO_INTEGRATION.md` + the official `Documentacion - Auto` PDF +
  the `Servicios Movipass` Postman collection. Re-read those for field-level detail.
- Live QA verified 2026-06-29: auth + reference GETs + quote routing all work.

## This connector is an SDK — the domain logic lives in `Kanvas\Insurance`

**Read [`src/Domains/Insurance/CLAUDE.md`](../../Insurance/CLAUDE.md) first.**
This folder is a typed client for Universal's API plus the adapter that implements the shared
contract. Orders, custom fields, statuses, GraphQL and workflow activities are
provider-agnostic and live in `Kanvas\Insurance`.

This is the **AgentRuntime shape**, not the payments one: the shared contract is a primary
domain, and each connector holds its own implementation of it — the same way
`Connectors/OpenClaw/Providers/OpenClawProvider.php` implements
`Intelligence\AgentRuntime\Contracts\AgentRuntimeProvider`.

The adapter is [`Providers/UniversalSegurosProvider.php`](Providers/UniversalSegurosProvider.php).
It is the only class that knows Universal's field names (`numeroCotizacion`, `terminos.prima`,
`Matricula`/`VideoInspeccion`). **Nothing outside it should reference them.**

There are no `universalSeguros*` GraphQL operations, and there is no connector-level Action or
Activity — that was the n+1 shape (a new mutation per insurer) this was refactored out of.

## The entity: a Souk **Order** backs each quote/policy

There is no bespoke "insurance quote" table. The cotización → póliza lifecycle rides on a
`Kanvas\Souk\Orders\Models\Order`. Mapping:

| Universal concept | Kanvas | Where |
|---|---|---|
| Cotización (quote) | **nothing persisted** — it is a price, returned by `insuranceQuote` | — |
| Póliza + the chosen quote | **Order** custom fields, generic keys | `Kanvas\Insurance\Enums\InsuranceCustomFieldEnum` |
| `requestId`, product code (A-PA…A-PL) | Order custom fields, Universal-only keys | `Enums/CustomFieldEnum` |
| Product (A-PA…A-PL) | catalog **Product/Variant** (a line item) | `Enums/ProductEnum` |
| Cliente (cédula, contacto) | **People** on the order | — |
| Vehículo + inspección | order item metadata (optionally a Vehicle) | quote `payload` |

Mapping an Order's people/vehicle into the quote payload is still the **caller's** job — the
`Order → QuoteRequest` builder does not exist yet.

## Layout

```
Client.php                      OAuth2 client_credentials + Redis token cache + problem+json error surfacing
Services/UniversalSegurosService one method per documented endpoint (catalogs, quote, docs, pay, emit, policy)
DataTransferObject/             QuoteRequest::make() → exact cotizar JSON (QuoteData/Vehiculo/Cliente/Terminos/…)
Handlers/UniversalSegurosHandler setup() — validates + stores company creds, does a real token round-trip
Providers/UniversalSegurosProvider implements the Kanvas\Insurance contracts — the only Universal↔Kanvas mapping
Enums/                          Environment, Configuration, Product, DocumentTransaction/Operation, CustomField
```

## Auth & multi-tenancy

- **OAuth2 client_credentials.** `Client::auth()` posts to the IDP `/connect/token`, caches the
  bearer in **Redis** keyed `universalSegurosToken-{appId}-{companyId}-{env}` (TTL = `expires_in − 300s`).
- **Credentials are company-scoped** (`$company->set(...)`) — they're the aliado's QA/prod creds.
  Environment + URLs are resolved **per-instance** from `EnvironmentEnum` (never static — Octane rule).
- **Environments:** `qa` and `prod`, each with its own API base + IDP URL (`EnvironmentEnum`).
  QA creds in the spec are QA-only; prod `client_id/secret` are an open item with Universal.

## Setup (no custom mutation)

Setup runs through the **generic** `createIntegrationCompany` mutation, which instantiates
`UniversalSegurosHandler` from the seeded `integrations.handler` column and calls `setup()`.
The `integrations` row (name `universal_seguros`, `apps_id=0`) is seeded by
`database/migrations/Workflow/2026_06_29_120000_add_universal_seguros_integration.php`. Its `config`
describes the setup fields: `environment`, `client_id`, `client_secret`, `scopes`.
`IntegrationsEnum::UNIVERSAL_SEGUROS = 'universal_seguros'`.

## End-to-end flow (§5 of the spec)

Every step is a method on `UniversalSegurosProvider`; who calls it and when is the domain
layer's business — see the Insurance CLAUDE.md table.

1. **Cotizar** — `quote(InsuranceQuoteRequest)` → returns `numeroCotizacion` + primas. Persists nothing.
2. **Docs** — `uploadDocuments($order, [InsuranceDocument, …])` → multipart `/documentos`, status `DOCUMENTS_UPLOADED`. (All products except A-PL require inspection — `requiresInspection($order)`.)
3. **Pago** — `requestPaymentLink($order, byEmail?)` → pay link / email, status `AWAITING_PAYMENT`. (Gateway-token form path `/pagos/generar-formulario` exists on the Service but isn't wired.)
4. **Emitir** — `emit($order)` → emit + read-back, stamps the policy number, status `EMITTED`/`POLICY_ACTIVE`.
5. **Sync** — `syncPolicy($order)`, driven by the generic `SyncInsurancePolicyActivity`, to follow pay+emit completed out-of-band.

## Gotchas / open items

- **QA chassis blocker (Universal's data, not our code):** the only seeded QA chassis
  `1FMCU0GXXDUA25874` returns a clean `400 "El chasis no puede ser asegurado"`. **Any other VIN
  returns `500`** (unhandled null in their registry lookup). `Client` surfaces a clear message;
  treat a 500 on cotizar as "chassis not in registry" until Universal fixes it. End-to-end issuance
  cannot be QA-tested until they seed an **insurable** chassis.
- **Error shape:** Universal returns RFC7231 problem+json; on validation errors a field-keyed
  `errors` map tells you the allowed values. `Client::toValidationException()` surfaces it verbatim —
  read the message, it names the correct enum values (`ocupacion`, `tipoDocumento`, `telefono` format, …).
- **A-PC (Por Si Chocas)** requires `vehiculo.sumaAsegurada`. **A-KM** primas come back as
  `primaFija` + `primaKm` instead of `prima`.
- **DTO casing:** use `debidaDiligencia` (the A-KM Postman sample misspells it `debidadiligencia`).
- **Emission is scoped per product.** `ConfigurationEnum::defaultScopes()` derives the scope list
  from `ProductEnum::emitScope()` so a new product can't ship without its emit scope. The old
  hardcoded string covered only 3 of 5 — A-PC and A-PT would have died at emit time, *after* the
  customer paid. Regression: `tests/Connectors/UniversalSeguros/ConfigurationScopesTest.php`.

## Tests

- `tests/Connectors/Traits/HasUniversalSegurosConfiguration.php` — sets company creds from
  `TEST_UNIVERSAL_SEGUROS_*` env, returns a `Client`.
- `tests/Connectors/UniversalSeguros/QuoteRequestTest.php` — pure DTO-shape tests (green, no network).
- `tests/Connectors/UniversalSeguros/ConfigurationScopesTest.php` — emit scopes cover every product.
- `tests/Insurance/UniversalSegurosProviderTest.php` — the adapter against a mocked service:
  response mapping, A-KM premium sum, document-type mapping, policy stamping.
- Live auth/quote/reference tests should follow the AppKey-guarded pattern (see `tests/CLAUDE.md`)
  and only run when `TEST_UNIVERSAL_SEGUROS_*` creds are present.

## TODO for the next dev

Connector-level only — the domain-level open items (payment decision, document-upload
trigger, unwired emit) live in the Insurance CLAUDE.md.

- [ ] Vehicle → quote payload builder (map the Kanvas vehicle product's custom fields into
      `vehiculo`). Until this exists the caller hand-builds the payload; the insurance-specific
      vehicle fields (`fuel_type`, `is_new`, `estimated_value`) don't exist on products yet.
- [ ] Wire the gateway-token pay form (`generatePaymentForm`) + return-URL handling — blocked on
      the payment decision.
- [ ] Live integration tests once Universal provides an insurable QA chassis.
- [ ] Confirm prod credentials/scopes and the allowed enum values (ocupacion/tipoDocumento/combustible/seguroLey).
- [ ] Response field names (`url` on the pay link, `numeroPoliza`/`numero` on the policy) were
      taken from the original implementation and never verified — the spec (`MOVIPASS_AUTO_INTEGRATION.md`,
      the Postman collection) is **not in the repo**. Confirm before prod.
