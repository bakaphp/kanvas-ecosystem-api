# Humano Seguros (API Intermediarios) — Auto Insurance Connector

Integration with **Humano Seguros' "API Intermediarios"** (`intermediarios-api.humano.com.do`),
consumed by the Movipass *aliado* as an intermediary. Quotes auto insurance and serves the
address and vehicle reference data the quote forms need.

> ⚠️ Not `UniversalSeguros` (the other auto insurer) and not `Intras`. Same vertical, separate
> insurer, separate folder.

## This connector is an SDK — the domain logic lives in `Kanvas\Insurance`

**Read [`src/Domains/Insurance/CLAUDE.md`](../../Insurance/CLAUDE.md) first.** This folder is a
typed client for Humano's API plus the adapter that implements the shared contract. Orders,
custom fields, statuses, GraphQL and workflow activities are provider-agnostic and live in
`Kanvas\Insurance`.

The adapter is [`Providers/HumanoProvider.php`](Providers/HumanoProvider.php). It is the only
class that knows Humano's field names (`numeroCotizacion`, `montoPrimaProrrata`, `cdPlan`,
`codigoDato`). **Nothing outside it should reference them.** There are no `humano*` GraphQL
operations.

## What this insurer can and cannot do

Their intermediary API is a **broker** API, not a policy-issuing one for auto. It covers:

| Capability | Endpoint | Contract implemented |
|---|---|---|
| Quote | `POST /cotizacion/productos/cotizar` | `InsuranceProviderInterface` |
| Quote detail | `GET /cotizacion/detalle` | `InsuranceProviderInterface::getQuote` |
| Address + vehicle reference data | `/catalogos/*`, `/vehiculos/*` | `CatalogProviderInterface` |
| The five auto plans | *(none — fixed table)* | `ProductCatalogProviderInterface` |

**There is no auto emission endpoint and no auto payment endpoint.** `POST /v2/productos/
buen-viaje/polizas` and `/pagos` exist but are travel-only (*Buen Viaje*), a different line we
do not sell. So `HumanoProvider` deliberately does **not** implement
`PolicyEmissionProviderInterface` or `PaymentLinkProviderInterface` — the gap is in their API,
not in this adapter, and a `not supported` stub would only surface it at runtime after the
customer had paid.

This is what forced the split of the old `PolicyProviderInterface` into
`PolicyEmissionProviderInterface` + `PolicySyncProviderInterface`. Humano could genuinely have
the second without the first; `GET /poliza/consulta` (Generales y Vida) is the read-back, and
wiring it is the next step, not part of this pass.

Also not wired: `POST /poliza/inspeccion/cargar-documento` (base64 inspection documents), which
would make this an `InspectionProviderInterface`.

## Auth & multi-tenancy

**Three static headers, no token exchange** — nothing to cache, no Redis:

| Header | Stored as |
|---|---|
| `Ocp-Apim-Subscription-Key` | `humano_subscription_key` |
| `x-user-key` | `humano_user_key` |
| `x-codigo-mediador` | `humano_mediator_code` |

**Credentials are company-scoped** (`$company->set(...)`) — they are the aliado's. Environment +
base URL resolve **per instance** from `EnvironmentEnum` (never static — Octane rule).

**Environments:** `dev` → `https://devapi.humano.com.do/api`, `prod` →
`https://huapi.humano.com.do/api`.

## Setup (no custom mutation)

Runs through the **generic** `integrationCompany` mutation, which instantiates `HumanoHandler`
from the seeded `integrations.handler` column and calls `setup()`. The `integrations` row (name
`humano`, `apps_id=0`) is seeded by
`database/migrations/Workflow/2026_09_20_120000_add_humano_integration.php`. Setup fields:
`environment`, `subscription_key`, `user_key`, `mediator_code`, `insurer_companies_id`.
`IntegrationsEnum::HUMANO = 'humano'`.

`insurer_companies_id` is **Humano's own company in Kanvas** — the owner of the seeded catalog
Products. Setup refuses without it rather than seeding them under the aliado.

Having no token endpoint to round-trip against, `setup()` validates credentials with the
cheapest authenticated read instead: `/catalogos/catalogo/provincias` takes no parameters and
every intermediary can see it, so a failure there is a credential problem and nothing else.

## The quote body is a bag of codes, not a typed payload

Unlike Universal's `vehiculo`/`cliente`/`terminos` blocks, Humano takes one generic `datos[]`
array keyed by `codigoDato`:

```json
{ "codigoDato": 250091, "valorDato": "0", "label": "Plan", "numeroBien": 1 }
```

So **`Enums/DatoEnum.php` *is* the request schema** — fourteen codes, all required. It also
carries `payloadKey()` (the readable key a caller sends) and `allowedValues()`.

`allowedValues()` exists because **their errors have no field key.** A failure comes back as
`{statusCode, message}` with a Spanish sentence, so a wrong `uso` and a wrong `fechaDesde` are
indistinguishable from the response — the opposite of Universal, whose validator answers a bad
enum with the full allowed set. `QuoteRequest::make()` therefore validates locally and reports
**every** missing field in one error; discovering fourteen required fields one round trip at a
time is not a reasonable form-filling experience.

Their own sample shows `statusCode: 401` inside an HTTP 400 body. **Trust the HTTP status, not
that field.**

## Plans: our slug out, their number in

`PlanEnum` is backed by readable slugs (`mi_auto_premier`), not by Humano's `"0".."4"`.

That is not cosmetic. Their code for the top plan is `"0"`, which is **falsy** — stamped on an
Order as the product code, every `empty()` check against it reads "no product" and the order
silently looks unquoted. `PlanEnum::code()` converts at the boundary, and `plan_code` rides in
the `InsuranceProduct` metadata; `HumanoProvider::plan()` accepts either spelling so a caller
holding a raw `cdPlan` from a catalog response need not translate it back.

| Slug | Their code | Commercial name |
|---|---|---|
| `mi_auto_premier` | 0 | Mi Auto Premier (Todo Riesgo Cero Deducible) |
| `mi_auto_full` | 1 | Mi Auto Full (Todo Riesgo Con Deducible) |
| `mi_auto_basico` | 2 | Mi Auto Básico (Seguro de Ley) |
| `mi_auto_flex` | 3 | Mi Auto Flex (Todo Riesgo Pérdida Total) |
| `mi_moto_basico` | 4 | Mi Moto Básico |

**The plan is the product — there is still no plan level to model.** Their quote takes exactly
one plan and the vehicle catalogs are filtered by it (`cdPlan` on every `/vehiculos/*` call),
which is why those catalogs are cached **per plan**: a key ignoring it would serve Mi Moto's
brands to a car quote.

`products()` marks every plan available. Their API exposes no entitlement signal — unlike
Universal, where the granted emit scopes say which lines we may actually sell — so a plan the
intermediary is not licensed for surfaces as a quote error instead.

## Search (`insuranceSearch`): crosswalk, version and canonical mapping

`HumanoProvider` is in the search fan-out (`VehicleReferenceQuoteProviderInterface`). Its vehicle catalogs are hierarchical,
per plan, and carry no year (year is its own dato), so they cannot be mirrored like Universal's flat model list:

- **Targeted crosswalk.** `scanVehicleCatalog()` walks, per plan, only the app's canonical vehicles: brand by normalized name
  against `/marcas`, model against `/modelos` of that brand, then **every** version of that model from `/versiones`. All calls go
  through the cached `getCatalog`. Rows land in `insurance_vehicle_model_map` with `plan_code` = plan slug,
  `external_brand_id` / `external_model_id` / `external_version_id` and `version_key` (a model-level row has `version_key = ''`).
  A plan whose brand catalog is empty, whose models come back empty or that throws is not reported as scanned, so it never
  deactivates rows. Run it with `BuildVehicleModelCrosswalkJob`.
- **Version is mandatory** (250004, no "any"). The canonical vehicle's free-text `version` is matched against `version_key`; no
  match or no version means the plan is skipped silently. The picker reads `insuranceCatalog` `vehicle_versions`
  (`plan`, `marca`, `modelo`), which returns Humano's `{payload: [{P_CD_DATO, P_DE_DATO}]}` unchanged.
- **Canonical to Humano** lives in `Services/CanonicalQuotePayloadMapper`: `usage` to `uso` 1..17, `marital_status` to S/C,
  `sex` to M/F/N, `circulation_zone` to 1..4, `birth_date` `Y-m-d` to `dd-mm-yyyy`, `age` derived from the birth date when absent.
  A birth date is never invented from an age (it is required). An unsupported value throws a `ValidationException` that lists
  the canonical values; a missing fact is named by `QuoteRequest`.
- **PROVISIONAL defaults.** `rc_exceso` and `suma_asegurada_auto_exceso` have no documented meaning. `config/insurance.php`
  `humano.*` defaults to `1` and `5000000` (cars) / `3000000` (`mi_moto_basico`), copied from their examples; an empty env falls
  back to those. The values actually sent are recorded in `QuoteResult::$assumptions`. Until Humano confirms them, set
  `INSURANCE_HUMANO_SEARCH_ENABLED=false` to keep Humano out of the search without a deploy. Confirm with Humano before relying on prices.
- **Incomplete underwriting** (`hasRequiredInformation`) is reported as `INCOMPLETE_INFORMATION`, never as an outage.
- **Empty catalog answers are never cached** (a 7-day TTL would pin a transient empty response).

## Prices: read Prorrata, never Anual

A quote response carries both sets:

```
montoPrimaAnual / montoComponenteAnual / montoTotalAnual
montoPrimaProrrata / montoComponenteProrrata / montoTotalProrrata
```

**Prorrata is what the chosen `codigoVigencia` actually costs; Anual is the full-term price.**
On an annual quote they are identical — their own sample returns 50265.54 twice — which is
exactly what makes Anual the tempting wrong answer: it prices a **monthly** policy at twelve
times the amount and no annual test would catch it. Prorrata is correct for every vigencia, so
that is what reaches `QuoteResult`. Regression:
`HumanoProviderTest::testPricesOffTheProratedAmountsNotTheAnnualOnes`.

`planesDePago[]` (the installment schedule, with `montoSiguienteCuota`) and the annual figures
stay in `QuoteResult::$raw`. **`montoPrimaPago` is an installment, not a total** — do not fold
it into `total`.

`cotizaciones` is a list because a quote can cover several insured assets. Auto quotes send a
single `numeroBien`, so the first line is the vehicle; the whole response is kept in `raw`.

## Gotchas / open items

- **`montoComponenteProrrata` → `tax` is an inference, not their documentation.** It is 16% of
  the premium in their sample, matching the ISC on insurance premiums in DR. Confirm against a
  live quote before prod; if "componente" turns out to bundle anything else, the mapping in
  `toQuoteResult()` is the single place to fix.
- **The `/cotizacion/detalle` response shape is undocumented.** Their page describes the
  content in prose — insured and intermediary data, plan, coverages, inspection status — but
  names no fields. `getQuote()` reads the same keys as a fresh quote and falls back to echoing
  the quote number, so it degrades to "raw only" rather than lying. **Map it properly against a
  live response before relying on it.**
- **`fechaDesde` — their doc contradicts itself.** The endpoint description says the quote date
  must be the current day; the 400 example says the start date must be *later* than today.
  `QuoteRequest` defaults to today. Verify which one their validator enforces.
- **`direccionIp` is required and cannot be invented here.** The caller knows whose request it
  is; `QuoteRequest` rejects a payload without it rather than guessing.
- **`/catalogos/catalogos/sectores` is pluralised** — unlike its two `/catalogos/catalogo/*`
  siblings. That is their spelling, not a typo in `HumanoService`.
- **Optional keys are omitted, never sent as null.** Carried over from the Universal connector,
  where an explicit `null` returned a bare 500. Not yet observed here — applied up front rather
  than learned the hard way.
- No credentials have been exercised yet: **nothing in this connector has touched their API.**
  Everything below "harvested from QA" in the Universal doc has no equivalent here yet.

## Tests

- `tests/Connectors/Humano/QuoteRequestTest.php` — pure DTO-shape tests (no network): the
  `datos[]` bag, the plan code going out as `"0"`, all-missing-fields-in-one-error, allowed-value
  rejection, omitted optionals.
- `tests/Insurance/HumanoProviderTest.php` — the adapter against a mocked service: the
  Prorrata/Anual split, plan resolution from either spelling, the product list, catalog caching
  per plan, missing-parent rejection. Uses the `array` cache store so hit/miss counting doesn't
  need Redis.
- `tests/Connectors/Humano/CanonicalQuotePayloadMapperTest.php` — each canonical-to-Humano mapping, its failure message,
  the date format, age derivation and the config defaults.
- `tests/Insurance/HumanoVehicleCrosswalkTest.php` — targeted crosswalk build against fixtures shaped like their OpenAPI
  (per plan, idempotent, manual rows, empty and partial catalogs) and the multi-code resolver.
- Live tests should follow the AppKey-guarded pattern (see `tests/CLAUDE.md`) and only run when
  `TEST_HUMANO_*` creds are present. **Not written yet — no credentials.**

## TODO for the next dev

- [ ] Get DEV credentials and verify: the `tax` mapping, the `/cotizacion/detalle` shape, the
      `fechaDesde` rule, and whether any `datos` field is in practice optional.
- [ ] Wire `GET /poliza/consulta` as `PolicySyncProviderInterface::syncPolicy`, so
      `SyncInsurancePolicyActivity` can follow a policy issued out of band.
- [ ] Wire `POST /poliza/inspeccion/cargar-documento` as `InspectionProviderInterface`
      (base64 multipart). Blocked on the same document-upload trigger as Universal.
- [ ] Decide what contracting means for an insurer that cannot emit or charge: the Order's
      terminal state here is not `EMITTED`, and `InsuranceStatusEnum` has no case for
      "handed off to the insurer".
- [ ] Confirm the `rc_exceso` / `suma_asegurada_auto_exceso` defaults and the canonical `usage` mapping with Humano.
- [ ] Business errors (e.g. vehicle value outside the plan range) arrive as HTTP 200 with `codigoError`; `toQuoteResult`
      maps them to a failed quote, which the search reports as `REJECTED`.
