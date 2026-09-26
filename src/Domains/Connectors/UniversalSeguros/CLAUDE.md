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
3. **Pago** — `reportPayment($order, InsurancePaymentReport)` → §4.5, status `PAID`. See below.
4. **Emitir** — `emit($order)` → emit + read-back, stamps the policy number, status `EMITTED`/`POLICY_ACTIVE`.
5. **Facturar** — `invoicePolicy($order, InsurancePaymentReport)` → §4.6. Universal issues the invoice; we only hand them the transaction and the policy number.
6. **Sync** — `syncPolicy($order)`, driven by the generic `SyncInsurancePolicyActivity`, to follow pay+emit completed out-of-band.

### We collect, then report — we do not use their gateway

Universal issued a MoviPass-specific revision of the spec whose §4.3 opens with it: *"Para
Movipass, el cobro al cliente se realiza en su plataforma mediante Azul y se reporta a UNIT según
el apartado 4.5."* So the three gateway options in §4.3 — email link, hosted link, and the
`/pagos/generar-formulario` token — **are not our path**, and `requestPaymentLink()` survives only
because the contract is shared with insurers that do collect. `generar-formulario` stays unwired
on purpose; don't "finish" it.

This needs **no collective policy, no `postpago`, no profile change, and not `/api/v1/emitir`** —
the individual flow is unchanged except that step 3 reports instead of charges. `asignar-informacion-pago`
only needs `cotizaciones` + `externos`, both of which the aliado token already carries.

Three things the shape forces:

- **Report off the authorization, not the capture.** Everything with an inspection is a Hold on
  their side (§4.3), and `AzulProcessor` reproduces it with `authorize()`/`capture()`. The funds
  stay reserved until the policy exists; a failed emission is voided and never captured. Voiding
  a *held* Azul transaction has no time limit, but voiding a *captured* one is 20 minutes only —
  which is why a plain Sale is the wrong call here.
- **`amount`/`tax` are currency units, not cents.** Their doc never says so; it is inferred from
  their own example (`amount: 5323, tax: 734` → 16% ITBIS on the 4589 base) and from the fact
  that a car premium of RD$53.23 is nonsense. `AzulProcessor::toCents()` means **the value we
  send Azul cannot be forwarded** — read `insurance_total` / `insurance_tax` off the Order.
  `itbis` is hardcoded `'000'` on the Azul side, so the tax never comes back from the processor
  either. Unverified: §4.5 answers `204` with no body, so a wrong magnitude is accepted silently.
  Confirm by reading the quote back.
- **No idempotency key.** Unlike the quote, §4.5 documents none, and a `204` cannot distinguish
  "created" from "already there". Report once, off the authorization.

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
  2. *Theirs:* a VIN their registry doesn't know. The lookup has an unhandled null, so an
     unseeded chassis returns `500` instead of a `400`.

  Their validation is otherwise good: bad enums come back as `400` with a field-keyed `errors`
  map naming the allowed values. Diagnose by sending a chassis QA currently accepts — if the
  `500` becomes a `400` or a green quote, the problem was the VIN; if it stays a `500`, it is
  your payload.
- **Which QA chassis works flips without warning — measure, don't trust this file.** Universal
  re-seeds their QA registry, and the two VINs swapped roles between 2026-06 and 2026-08. As of
  **2026-08-27**, measured live against Kanvas' `insuranceQuote`:

  | Chassis | QA today (2026-08-27) | Previously (as first documented) |
  |---|---|---|
  | `1FMCUOGXXDUA25874sodfaojk` (letter `O`) | **quotes green** — complete and express modes | `500`, unknown VIN |
  | `1FMCU0GXXDUA25874` (digit `0`) | `400 "El chasis del vehículo no puede ser asegurado"` | the only seeded VIN |

  Note the `O`-vs-`0` at position 6 — the two differ by one character and the wrong one is a
  silent `500`. The old claim that *only* `1FMCU0GXXDUA25874` is seeded no longer holds, and the
  quote path is no longer blocked: **A-PA quotes end-to-end today**. Issuance is still unproven —
  confirm with Universal which VIN their QA has enabled for emission before assuming a green
  quote means a green policy.
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
  The presence of `primaFija`, not a falsy `prima`, is what distinguishes the two shapes — for
  the *rate*. It does **not** pick the premium: A-PA sends `primaFija` too, but zero (found
  2026-08-27), so `primaFija` wins only when it is `> 0` and otherwise falls back to `prima`.
  Regression: `testPerKilometerProductPricesOffTheFixedPremiumNotTheZeroPrima`.
- **The quote response envelope is inconsistent — read `data.*` first.** `terminos` is nested under
  `data`, and so is `numeroCotizacion`, despite the doc's sample showing it at the root. Reading the
  root alone left `quote_number` empty and marked valid A-PA quotes `success:false` (fixed
  2026-08-27). New fields: try `data.<field> ?? <field>`, never the root alone.
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
