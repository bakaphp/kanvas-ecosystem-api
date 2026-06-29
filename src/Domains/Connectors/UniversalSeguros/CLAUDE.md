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

## The entity: a Souk **Order** backs each quote/policy

There is no bespoke "insurance quote" table. The cotización → póliza lifecycle rides on a
`Kanvas\Souk\Orders\Models\Order`, consistent with the rest of the Movipass app (its
`OrderTypeEnum` already distinguishes order subtypes). Mapping:

| Universal concept | Kanvas | Where |
|---|---|---|
| Cotización (quote) → Póliza (emitted) | the **same Order** through its lifecycle | — |
| `numeroCotizacion`, `numeroPoliza`, `requestId`, primas, status | **Order custom fields** | `Enums/CustomFieldEnum` |
| Product (A-PA…A-PL) | catalog **Product/Variant** (a line item) | `Enums/ProductEnum` |
| Cliente (cédula, contacto) | **People** on the order | — |
| Vehículo + inspección | order item metadata (optionally a Vehicle) | quote `$input` |
| Status lifecycle | `Enums/InsuranceOrderStatusEnum` stamped on the order | — |

**Actions take an `Order`, call Universal, and stamp the result back onto it.** The mapping from
an Order's people/vehicle into the quote `$input` array is the **caller's** job (resolver or a
future `Order → QuoteRequest` builder) — the connector does not assume an order shape.

## Layout

```
Client.php                      OAuth2 client_credentials + Redis token cache + problem+json error surfacing
Services/UniversalSegurosService one method per documented endpoint (catalogs, quote, docs, pay, emit, policy)
DataTransferObject/             QuoteRequest::make() → exact cotizar JSON (QuoteData/Vehiculo/Cliente/Terminos/…)
Actions/                        CreateQuote, UploadInspectionDocuments, RequestPaymentLink, EmitPolicy, SyncPolicyStatus
Handlers/UniversalSegurosHandler setup() — validates + stores company creds, does a real token round-trip
Enums/                          Environment, Configuration, Product, DocumentTransaction/Operation, CustomField, InsuranceOrderStatus
Activities/                     SyncUniversalSegurosPolicyActivity (#[WorkflowAction], auto-discovered)
```

GraphQL: `graphql/schemas/Connector/universalSeguros.graphql` + `app/GraphQL/Connector/UniversalSeguros/`.

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

1. **Cotizar** — `CreateQuoteAction($order, ProductEnum, $input)` → stamps `numeroCotizacion` + primas, status `QUOTED`.
2. **Docs** — `UploadInspectionDocumentsAction($order, matriculaPath, videoPath)` → multipart `/documentos`, status `DOCUMENTS_UPLOADED`. (All products except A-PL require inspection.)
3. **Pago** — `RequestPaymentLinkAction($order, byEmail?)` → pay link / email, status `AWAITING_PAYMENT`. (Gateway-token form path `/pagos/generar-formulario` exists on the Service but isn't wired to an Action yet.)
4. **Emitir** — `EmitPolicyAction($order)` → emit + read-back, stamps `numeroPoliza`, status `EMITTED`/`POLICY_ACTIVE`.
5. **Sync** — `SyncUniversalSegurosPolicyActivity` polls `getPolicy` to follow pay+emit completed out-of-band.

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
- Scopes are space-separated; only request scopes you're permitted to use (`ConfigurationEnum::DEFAULT_SCOPES`).

## Tests

- `tests/Connectors/Traits/HasUniversalSegurosConfiguration.php` — sets company creds from
  `TEST_UNIVERSAL_SEGUROS_*` env, returns a `Client`.
- `tests/Connectors/UniversalSeguros/QuoteRequestTest.php` — pure DTO-shape tests (green, no network).
- Live auth/quote/reference tests should follow the AppKey-guarded pattern (see `tests/CLAUDE.md`)
  and only run when `TEST_UNIVERSAL_SEGUROS_*` creds are present.

## TODO for the next dev

- [ ] `Order → QuoteRequest` builder (map People + vehicle custom fields into the quote `$input`).
- [ ] Wire the gateway-token pay form (`generatePaymentForm`) to an Action + return-URL handling.
- [ ] Upload inspection files straight from the Order's `Filesystem` attachments (temp-path bridge).
- [ ] Live integration tests once Universal provides an insurable QA chassis.
- [ ] Confirm prod credentials/scopes and the allowed enum values (ocupacion/tipoDocumento/combustible/seguroLey).
