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

Setup runs through the **generic** `integrationCompany` mutation (the resolver method behind it
is named `createIntegrationCompany` — don't send that as the operation name), which instantiates
`UniversalSegurosHandler` from the seeded `integrations.handler` column and calls `setup()`.
The `integrations` row (name `universal_seguros`, `apps_id=0`) is seeded by
`database/migrations/Workflow/2026_06_29_120000_add_universal_seguros_integration.php`. Its `config`
describes the setup fields: `environment`, `client_id`, `client_secret`, `scopes`,
`insurer_companies_id`. `IntegrationsEnum::UNIVERSAL_SEGUROS = 'universal_seguros'`.
That `config` is descriptive only — `BaseIntegration` hands `setup()` the raw `$data`, so a
field the handler reads works whether or not it is listed there (`verify_ssl` is one).

`insurer_companies_id` is **Universal's own company in Kanvas** — the owner of the seeded
catalog Products. Setup refuses without it rather than seeding them under the aliado by
accident. On success `setup()` also stamps `InsuranceCustomFieldEnum::PROVIDER` on the aliado's
company (without it every `insuranceQuote` would have to name the insurer explicitly) and
dispatches `SyncInsuranceProductsJob`, which seeds the five products asynchronously.

**There is no products endpoint.** `ProductEnum` *is* the catalog — §4.1 of their doc is a fixed
table of five. `products()` returns them; `ProductEnum::label()` carries their commercial names.
The customer-facing copy is authored on the seeded Kanvas Product, not in the enum.

## End-to-end flow (§5 of the spec)

Every step is a method on `UniversalSegurosProvider`; who calls it and when is the domain
layer's business — see the Insurance CLAUDE.md table.

1. **Cotizar** — `quote(InsuranceQuoteRequest)` → returns `numeroCotizacion` + primas. Persists nothing.
2. **Docs** — `uploadDocuments($order, [InsuranceDocument, …])` → multipart `/documentos`, status `DOCUMENTS_UPLOADED`. (All products except A-PL require inspection — `requiresInspection($order)`.)
3. **Pago** — `requestPaymentLink($order, byEmail?)` → pay link / email, status `AWAITING_PAYMENT`. (Gateway-token form path `/pagos/generar-formulario` exists on the Service but isn't wired.)
4. **Emitir** — `emit($order)` → emit + read-back, stamps the policy number, status `EMITTED`/`POLICY_ACTIVE`.
5. **Sync** — `syncPolicy($order)`, driven by the generic `SyncInsurancePolicyActivity`, to follow pay+emit completed out-of-band.

## Allowed values and cross-field rules (harvested from QA, not in their doc)

Their doc names none of these; every list below came from provoking a `400` with a bogus value,
because their validator answers with the full set. Do that again rather than guessing when you
hit a new enum.

| Field | Allowed values |
|---|---|
| `vehiculo.combustible` | `Gas`, `Gasolina / Diesel`, `Vehículo Electrico` |
| `vehiculo.inspeccion.tipo` | `Pre-inspeccionado y Carga de Matrícula`, `Solicitar video inspección (Incluye Carga de Matrícula)`, `Carga de Conduce`, `Cargar matrícula` |
| `terminos.seguroLey` | `Auto Exceso`, `Auto Exceso+`, `Plus`, `Base`, `No` |
| `terminos.autoSustituto` | `Rent-a-Car`, `Uber`, `No` |
| `terminos.fraccionamientoPago` | `CP`, `PU`, `M`, `T`, `C`, `A` |

Cross-field rules they enforce and the doc omits:

- `inspeccion.tipo: "Carga de Conduce"` **requires** `esCeroKm: true` — it is the brand-new-vehicle
  path, where there is a bill of sale instead of a plate registration.
- `esCeroKm: true` is only accepted when `anio` is the current year, the previous one or the next.

## Gotchas / open items

- **`cURL error 60` on QA is Universal's TLS, not ours.** Since their 2026-07-23 reissue,
  `qa.universal.com.do` serves a chain terminating in `GoDaddy TLS Root CA - R1` (created
  Aug 2025). That root is **not in Mozilla's store** — checked against `curl.se/ca/cacert.pem`
  dated 2026-08-13, which ships only `Go Daddy Root Certificate Authority - G2`. So it fails on
  Debian/Alpine/Java/Python/Node alike; it *looks* fine in a browser because Windows and macOS
  trust it (Microsoft's program has it) and Schannel chases AIA. **Prod is unaffected** —
  `api.universal.com.do` and `idp.universal.com.do` chain to DigiCert Global Root G2 and verify
  cleanly from the container. The fix is theirs: reissue QA under the same CA as prod, or serve
  a cross-signed chain to the G2 root.

  Meanwhile `ConfigurationEnum::VERIFY_SSL` (`universal_seguros_verify_ssl`, also `verify_ssl`
  in the `integrationCompany` setup payload) turns Guzzle's peer verification off **per
  company**. Unset/absent/garbage ⇒ verification stays **on** — it only goes off on an explicit
  falsy value. Set it on the QA aliado company only; a prod company with this off is
  MITM-able on a flow carrying policy and cardholder-adjacent data. Revert it the moment
  Universal fixes QA. Regression: `tests/Connectors/UniversalSeguros/ClientSslVerificationTest.php`.

- **"Campo no obligatorio" means OMIT the key, not send `null`.** Their deserialiser throws a
  bare `500 "Ha ocurrido un error desconocido"` on at least one explicit null —
  `terminos.ceroDeducible` — while the byte-identical body without that key returns a clean
  `400`. Spatie Data serialises every unset optional as `null`, so `QuoteRequest::toArray()`
  strips nulls recursively before the POST. Empty arrays are kept (`aditamentos: []` means
  "none", and round-trips fine). Bisected against QA 2026-08-10; regression in
  `QuoteRequestTest::testUnsetOptionalsAreOmittedRatherThanSentAsNull`.
  **If you add a nullable field to any request DTO here, it inherits this protection — do not
  bypass `toArray()`.**
- **A `null` from the client is not the same as an omitted key, and used to crash us before the
  request left.** Spatie passes a null straight into promoted properties like
  `string $cupon = ''`, so PHP raises a TypeError and the FE sees a bare "Internal server
  error". Every scalar-with-a-default across the nine request DTOs has that hazard, so
  `QuoteRequest::make()` strips nulls from the **input** as well — symmetric with `toArray()`
  stripping them from the output. Do not "fix" this by making one DTO nullable; the boundary is
  the right place and new fields inherit it. Regression:
  `testNullsFromTheClientFallBackToTheDefaultInsteadOfCrashing`.
- **Their doc's "campo no obligatorio" is wrong for `terminos.fraccionamientoPago` and
  `terminos.formaPago` — both are required in practice.** Omitting `fraccionamientoPago` returns
  a clean `400` naming the allowed values (`CP, PU, M, T, C, A`); omitting `formaPago` returns a
  bare `500`. Send both (`M` / `t/c` for individual policies). This is the second field after
  `ceroDeducible` where their doc and their runtime disagree — trust the runtime.
- **Always send `vehiculo.inspeccion`, including for A-PL.** Their doc says Seguro de Ley needs
  no inspection, and `requiresInspection()` reflects that for *document upload* — but omitting
  the block from the quote body returns `500`. With the block present the same request returns
  a clean `400`.
- **A bare `500` has two unrelated causes. Rule out ours before blaming theirs.**
  1. *Ours:* a key we should have omitted (see the null rules above). Fixed at the boundary, but
     any new hand-built payload can reintroduce it.
  2. *Theirs:* an unknown VIN. Their registry lookup has an unhandled null, so **any chassis other
     than `1FMCU0GXXDUA25874` returns `500`** — verified across four spellings, with the plate
     making no difference. Only that one VIN is seeded in QA.

  Their validation is otherwise good: bad enums come back as `400` with a field-keyed `errors`
  map naming the allowed values. Diagnose by sending the known VIN — if the `500` becomes a
  `400`, the problem was the VIN; if it stays a `500`, it is your payload.
- **QA chassis blocker (genuinely theirs):** `1FMCU0GXXDUA25874` — the one VIN they know — returns
  `400 "El chasis del vehículo no puede ser asegurado"`. So the only seeded chassis is
  deliberately *not* insurable. End-to-end issuance cannot be QA-tested until Universal seeds an
  insurable one. This is the only thing standing between us and a live quote.
- **Anexo A's chassis reads `1FWCU…` in the PDF — that is an OCR artefact.** `FM` is Ford's real
  WMI and the only prefix their registry accepts. Don't retype VINs out of the PDF images.
- **Error shape:** Universal returns RFC7231 problem+json; on validation errors a field-keyed
  `errors` map tells you the allowed values. `Client::toValidationException()` surfaces it verbatim —
  read the message, it names the correct enum values (`ocupacion`, `tipoDocumento`, `telefono` format, …).
- **A-PC (Por Si Chocas)** requires `vehiculo.sumaAsegurada`.
- **A-KM prices are not a sum, and `prima` is not the tell.** Their doc's own sample returns
  `primaFija: 1000, primaKm: 5.85, prima: 0, totalCobro: 1000`. Two traps in one response:
  reading `prima` first prices the product at **0** (it is present and falsy, so `??` never
  falls through), and adding the two components prices it at **1005.85** — `primaKm` is a rate
  *per kilometer driven*, not an amount. The premium is `primaFija`; the rate rides separately
  on `QuoteResult::$ratePerKm` → `rate_per_km` in GraphQL → `insurance_rate_per_km` on the Order.
  The presence of `primaFija`, not a falsy `prima`, is what distinguishes the two shapes.
  Regression: `testPerKilometerProductPricesOffTheFixedPremiumNotTheZeroPrima`.
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
  response mapping, A-KM premium/rate split, catalog caching + local vehicle-model filtering,
  the product list, document-type mapping, policy stamping. Uses the `array` cache store so
  hit/miss counting doesn't need Redis.
- `tests/Insurance/SyncInsuranceProductsActionTest.php` — the product seed against the DB: five
  rows, the insurer's code on each, and a re-run leaving admin-edited copy alone. Needs
  `$connectionsToTransact = [null, 'inventory']` or the rows survive the rollback.
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
