# Insurance — Provider-Agnostic Insurance Domain

The graph never names an insurer. Adding one is a container binding plus an adapter —
never a new mutation.

## Why this is a top-level domain, and where its pieces live

Two placement questions come up every time; both are already answered by the codebase.

**Why not `Souk`?** Souk is the mechanics of *selling* — Orders, Cart, Payments,
Discounts, Loyalty, Wallet. Nothing in it is a thing you sell. Quote → inspection →
policy is a lifecycle, and the precedent for that is `Event` (tee-time bookings), which
is top-level even though the slot it sells is an `Inventory` Variant.

**Why not `Inventory`?** Inventory is catalog: Products, Variants, Warehouses,
Attributes. The local plans catalog (insurer → plan → coverage / exclusion /
requirement, per `.planning/movipass/features/03_seguros`) is deliberately **not**
Products: plans carry insurance-only fields the comparator orders on (`type`,
`sponsored`, `commission_rate`, `recommended_score`) and nothing is ever sold from them
directly. They are native models in `Models/` on their own `insurance` connection (`database/migrations/Insurance/`,
`composer migrate-insurance`); orders and payments stay on `commerce`, vehicles and plan products on `inventory`.
`insuranceCatalog` is still a passthrough to the insurer's API and persists nothing.

Catalog rules worth knowing:
- Tenancy is on `insurers` (`apps_id`, `companies_id`). Plans and their children inherit it
  through the insurer, via `InsurancePlan::fromInsurerTenant`.
- `law` / `full` is the canonical coverage category, resolved by `InsuranceProductCategoryMapper`
  from an adapter's own product code; an unknown provider or code throws.
- `SeedInsuranceCatalogAction` only fills in what is missing, including rows an admin
  soft-deleted, and never rewrites an edited row.
- Admin CRUD is `@guardByAdmin`; a consumer-facing read surface belongs to the comparator.

**Why isn't this a connector?** Same reason `AgentRuntime` isn't. Per
[`Connectors/CLAUDE.md`](../Connectors/CLAUDE.md), a shared contract with N provider
implementations is a **primary domain**; the connector folders hold only the
per-provider implementations. Burying the contract inside one provider's folder leaves
the second provider with nothing to hang off.

So: contract, DTOs, enums, factory, actions and activities live here. Each adapter lives
in its own connector — `Connectors/UniversalSeguros/Providers/UniversalSegurosProvider.php`
and `Connectors/Humano/Providers/HumanoProvider.php` — mirroring
`Connectors/OpenClaw/Providers/OpenClawProvider.php`.

The two are deliberately unequal in surface: Universal implements every contract,
Humano only quote + catalogs + products, because that is all their API exposes for auto
(see `Connectors/Humano/CLAUDE.md`). An insurer declaring less is the layer working as
intended, not an unfinished adapter.

Note this differs from **payments**, where `AzulProcessor` sits in
`Souk/Payments/Infrastructure/Processors/`. Payments is the older shape; AgentRuntime is
the one with a written rule. Payments is still the right mirror for the *pattern* —
`PaymentProcessorInterface` → `ProcessorFactory` → `PaymentProcessorServiceProvider` —
just not for placement.

## The two rules that keep it agnostic

**1. The provider is read off the entity, never sent by the client.** Payments does
`ProcessorFactory::make($payment->paymentMethod?->processor, ...)`; insurance does
`InsuranceProviderFactory::forOrder($order)`, resolving
`InsuranceCustomFieldEnum::PROVIDER` stamped on the Order at contract time. Only the
pre-Order quote takes a `provider` argument, because there is no entity yet.

**2. Only what the FE genuinely needs synchronously is a direct operation.**
Everything else runs as a workflow activity off the order's status — the same shape
as Paso Rápido and the other Movipass verticals, which expose 2 mutations against 11
activities.

## Direct surface: two queries, zero mutations

```graphql
insuranceCatalog(catalog: String!, params: Mixed, provider: String): Mixed
insuranceQuote(product: String!, input: Mixed!, provider: String): InsuranceQuoteResult!
```

- **Catalog** — reference data (vehicle models, address trees, add-ons) to render the
  forms. Shapes are the insurer's own, so it is a deliberate passthrough; the
  alternative is one query per catalog per insurer, which is the n+1 this layer exists
  to remove.
- **Quote** — a **price**, and deliberately Order-free. Comparing five insurers must
  not leave five half-built orders behind, exactly as you don't create a `payments`
  row to compare processors. It returns the insurer's quote reference; the Order is
  created only when the customer picks one.

Setup stays on the generic `integrationCompany` mutation.

## Everything past "I'll take this one"

The Order is created either by the cart (`createOrderFromCart`, see "Order shape and the cart path"), by
`contractInsurancePlan` (same shape, built by the backend) or, for legacy FE orders, with `metadata.insurance.{provider, quote_number}`.
Whatever the origin, everything reads the order through `ReadInsuranceOrderAction`. From there:

| Step | Runs as | Trigger |
|---|---|---|
| Bind the quote, send the customer, stamp premium/tax/total | `AttachInsuranceQuoteActivity` | order created |
| Push inspection documents | `UploadInsuranceDocumentsActivity` | attach-file |
| Report the payment we took | `ReportInsurancePaymentActivity` | card authorized |
| Emit the policy, capture the hold | `EmitInsurancePolicyActivity` | payment reported |
| Invoice the policy | `InvoiceInsurancePolicyActivity` | policy emitted |
| Build the PDF carnet | `GenerateInsuranceCarnetActivity` | policy invoiced (policy active) |
| Email the policy and carnet | `SendInsurancePolicyEmailActivity` | carnet generated |
| Poll the policy back | `SyncInsurancePolicyActivity` | schedule / status change |
| Customer backs out | `CancelInsuranceOrderActivity` | order cancelled |

Whether the insurer collects or we do is the insurer's call, so both shapes are contracts:
`PaymentLinkProviderInterface` hands the cardholder to their gateway, `PaymentReportProviderInterface`
settles on our side and tells them afterwards. Universal is the second; the adapter's CLAUDE.md has
why, and why reporting runs off the authorization rather than the capture.

`AttachQuoteToOrderAction` **re-reads prices from the insurer** rather than trusting
the client's quote payload, so a tampered payload can't set its own premium.

## Contracts

`InsuranceProviderInterface` is the only required one — `name()`, `integration()`,
`quote()`, `getQuote()`. `integration()` is what lets a single generic activity call
`executeIntegration()` for any insurer instead of one activity per brand.

Everything else is opt-in and checked with `instanceof` at the call site:
`InspectionProviderInterface`, `PaymentLinkProviderInterface`,
`PolicyEmissionProviderInterface`, `PolicySyncProviderInterface`,
`CatalogProviderInterface`, `ProductCatalogProviderInterface`.

**Issuing a policy and reading one back are separate contracts.** They were one
`PolicyProviderInterface` while Universal was the only insurer, because Universal does
both. Humano's intermediary API quotes auto and serves the resulting policy but issues
only its travel line, so a combined contract would have forced an `emit()` that throws
— a promise that only breaks at runtime, after the customer has paid. Any capability
one insurer can have without the other belongs in its own interface.

## Persist what we author, cache what they own

Two different problems that look alike, and conflating them is how you end up
maintaining a copy of someone else's database.

**Products are persisted** — seeded as catalog `Products` under the insurer's own
company at integration setup (`insurer_companies_id`, a required setup field). Not to
save API calls: Universal has no products endpoint at all, its five codes are a fixed
table in their doc. They live in Kanvas because the copy the customer reads is *ours*
and someone edits it there. `SyncInsuranceProductsAction` is therefore a seed, not a
sync — it only fills in what is missing, and never rewrites a row an admin has
touched. The FE reads them through the normal `products` query; there is no
`insuranceProducts`.

**Reference data is cached** — vehicle models, address trees, add-ons. It is theirs,
we only read it, and nothing joins against it. `CatalogCache` is the shared mechanism;
the TTL per catalog is the adapter's call, because only it knows which of its
endpoints are stable. Rent-a-car options hang off a plan revision that only exists
after a quote, so they are per-customer and cached at TTL 0.

**There is no plan level to model.** Universal's `codPlan`/`revPlan` come back *in the
quote response* (§3.2 of their doc) — a plan can't be listed or chosen up front. The
product is the unit the customer picks. Humano arrives at the same place from the other
direction: it *does* name plans up front, but its quote takes exactly one and its
vehicle catalogs are filtered by it, so the plan simply **is** the product there.

## Custom fields — hybrid on purpose

Shared concepts are generic (`InsuranceCustomFieldEnum`: provider, status, quote
number, policy number, payment url, premium, tax, total) so the FE reads the same keys
whichever insurer backs the order. Anything with no cross-insurer meaning stays on the
connector's own enum — for Universal that's `universal_seguros_request_id` and their
product code.

## Statuses and the held payment

The charge stays an authorization until the policy exists. `EmitInsurancePolicyActivity` captures it
on success; an insurer refusal (`ValidationException` from the adapter) sets `FAILED` and voids it.
A transient transport error is *not* a refusal — it propagates and the hold is untouched.
Report and Emit share that rule through `FailsReleasingHeldPayment`: a ValidationException from the insurer, a `success=false` report, a missing quote number or an adapter that cannot report/emit sets `FAILED` and voids the hold; a transport error (any other exception) never does. Missing inspection documents and "not reported yet" are recoverable ordering problems and do not void. `CancelInsuranceOrderActivity` voids the hold and sets `CANCELLED`, and refuses once a policy exists
(that is a refund, not a void). `SettleInsurancePaymentAction` only touches an `AUTHORIZED` payment.

## The customer

Universal validates the customer and chassis on the quote before it can be emitted (§4.3 step 1), so the
order carries the customer snapshot under `metadata.data.customer` (canonical keys; the adapter builds the insurer block). Legacy orders carry `metadata.insurance.cliente` (the insurer-shaped block) beside
`metadata.insurance.product` and `metadata.insurance.payload`. `AttachQuoteToOrderAction` PUTs it onto the
quote through the opt-in `QuoteUpdateProviderInterface` *before* re-reading the prices, so the stamped
totals are the final ones. The PUT replaces the whole quote, which is why `payload` has to be stored too.

## Documents

Files are typed by the attachment's `field_name`, which must be an `InsuranceDocumentTypeEnum` value
(`registration`, `inspection_video`, `identification`); anything else on the order is never forwarded.
Already-sent filesystem ids live in `UPLOADED_DOCUMENTS`, because attach-file fires on every
attachment. Emission is refused for an inspection-required product until something has been sent.

## Search: `insuranceSearch` and picking a plan

`insuranceSearch(input: CanonicalQuoteInput!)` fans out over the **local catalog** (active insurers x their
active plans of the requested `law`/`full` category) and persists nothing. Catalog tenancy and credentials both
come from `InsuranceProviderFactory::credentialCompany`, so a marketplace client searches the platform's catalog.

- **Opt-in contract.** An adapter joins the search by implementing `CanonicalQuoteProviderInterface`
  (`requiresVehicleModelId()` + `quoteCanonical()`). It maps the canonical vehicle/underwriting facts to its own shape;
  insurer field names never leave the adapter. An id-based insurer whose `InsuranceVehicleModelResolver` answer is null
  is skipped silently (not a failure) and never called.
- **Vehicle crosswalk.** `insurance_vehicle_model_map` is keyed by (insurer, `plan_code`, brand, model, `version_key`, year).
  Universal uses plan `''` and version `''` with one `external_model_id` (`resolve()`); Humano uses its plan slug and carries
  `external_brand_id` / `external_model_id` / `external_version_id` (`resolveReference()`), because its catalogs are per plan and
  hierarchical. `BuildVehicleModelCrosswalkJob` runs either `VehicleModelCatalogProviderInterface` (whole catalog) or
  `TargetedVehicleCatalogProviderInterface` (walks only the app's canonical vehicles and reports which plans it fully scanned, so a
  failed or empty plan never deactivates rows). Manual rows are never touched.
- **Humano is in the fan-out when mapped.** `HumanoProvider` also implements `VehicleReferenceQuoteProviderInterface`: its plan is
  skipped silently (not a failure, never called) unless the crosswalk holds brand + model + version codes for that plan and the
  canonical vehicle carries a `version` (free text, matched by `VehicleKeyNormalizer`; the picker reads
  `insuranceCatalog` `vehicle_versions`). A search without enough underwriting facts is an `INCOMPLETE_INFORMATION` failure (customer-safe message, no field names), not a skip. Canonical
  underwriting values (`VehicleUsageEnum`, `MaritalStatusEnum`, `SexEnum`, `CirculationZoneEnum`, `birth_date` as `Y-m-d`) are
  translated inside `Humano\Services\CanonicalQuotePayloadMapper`. `rc_exceso` and `suma_asegurada_auto_exceso` are provisional
  business defaults in `config/insurance.php` (`humano.*`); their meaning is undocumented by Humano. `humano.search_enabled` (`INSURANCE_HUMANO_SEARCH_ENABLED`) switches Humano out of the search silently; the applied values ride in `QuoteResult::`.
- **Ranking** (`InsuranceComparatorService`, pure): sponsored, then commission, then `recommended_score` (then `can_emit`
  true/null/false), then lowest price, then plan id. Commission is the adapter's figure, else `premium * plan.commission_rate`.
  It is **internal-only**: it is never part of the customer-facing GraphQL types.
- **`is_recommended`** is `recommended_score > 0 AND can_emit !== false`, independent of the entry's rank.
- **Partial results.** Each plan quote is a `PlanQuoteTask` that never throws; it rebinds the app (`overwriteAppService`) in the
  worker. A rejection, an outage or a timeout becomes an `InsuranceSearchFailure`, reported per insurer only when none of its
  plans produced a quote. `failures[].message` is a fixed text per reason (`customerMessage()`); detail goes only to the log
  (exception class, plan code, HTTP status).
- **Concurrency.** `OctaneQuoteTaskRunner` uses `Octane::concurrently()` (Swoole task workers) inside the server and runs
  sequentially anywhere else (CLI, queue, tests). The service dispatches in chunks of `insurance.task_workers` (default 2, match
  `--task-workers`), so a `TaskTimeoutException` loses only its chunk (`TIMEOUT`); any other dispatch error is `UNAVAILABLE`.
  The timeout (`insurance.search_timeout_ms`) is per chunk, not per insurer, and the adapters' Guzzle clients keep their own
  hard 60s limit: a per-call HTTP timeout would need a Client change.
- **Cache.** One entry per (app, credential company, plan, vehicle signature), TTL `insurance.search_cache_ttl`; only successful
  quotes are stored, with `raw` stripped. The signature excludes `ip_address`, which the server always overwrites from the request.
- **Quote binding.** At response time each returned `(quote_number, plan_id)` is bound to the requesting user in a short-lived
  cache entry (`InsuranceQuoteBinding`, `insurance.quote_binding_ttl`), never inside the shared plan-quote cache. When the search
  input carried `vehicle.variant_id`, that id is bound with the quote (`vehicleVariantIdFor`); a search with typed-in data binds none
  and still works, it just cannot be contracted.
- **Selecting.** `selectInsurancePlan(plan_id, quote_number)` requires that binding, re-reads the quote from the insurer (never
  trusts client prices), rejects it when the quote's product code differs from the plan's `external_code`, then creates or updates
  a Guild **Lead** (the pre-payment quote; an Order only appears on payment) carrying the `insurance_*` custom fields, including
  `insurance_vehicle_variant_id` from the binding (removed again when a re-select has none).
  Idempotent per (customer people, plan) while the lead is open. No `variantInterest`: insurance plans are not Inventory variants.
## Contracting: `contractInsurancePlan` turns the Lead into the Order

`contractInsurancePlan(input: {lead_id, customer})` runs `ConvertInsuranceLeadToOrderAction` and returns the Order the
customer then pays through the normal `createPaymentMethod` -> `addPaymentToOrder` -> `processPayment` (hold) path. It is a
dedicated mutation, not an extension of `createOrderFromCart`: that path needs a cart and a Stripe payment intent, and
teaching a generic Souk action about Guild leads would cross domains the wrong way.

- **The Lead is identity, the insurer is the price.** A customer can write `insurance_*` custom fields on their own lead with
  `updateLead`, so those fields are never trusted for money. `SelectInsurancePlanAction` stamps `insurance_quote_signature`
  (`InsuranceQuoteSignature`, HMAC-SHA256 over user | plan | provider | quote number | total | tax | vehicle variant id, keyed with `app.key`);
  conversion refuses a lead whose signature no longer matches ("no longer valid, select again"). Even a valid lead only
  supplies *which* quote: total, tax and premium on the Order come from `provider->getQuote()` read at contract time. Rotating
  `APP_KEY` invalidates every open lead's signature, which just means customers select again. The same happens to leads
  signed before the vehicle variant id joined the signature: they have no vehicle anyway, so they must be selected again.
- **The insurer is told before anything is charged.** Inside the conversion the customer is PUT onto the quote
  (`PushCustomerToQuoteAction`, the same code `AttachQuoteToOrderAction` uses) and the quote is re-read, **before the Order
  exists**. An insurer rejection of the customer surfaces as a `ValidationException` pre-payment and creates no Order.
  The request is the one the cart path sends (`InsuranceOrderData::quoteRequest`, built in memory from the vehicle, plan and
  customer). `StampInsuranceQuoteAction` (shared with Attach) then writes the `insurance_*` fields and status `QUOTED`.
  `AttachInsuranceQuoteActivity` therefore skips any order whose insurance status is already set.
- **Tenant and owner checks.** The lead is looked up `getByIdFromCompanyApp`, must belong to the acting user, and its plan
  must still be active in the credential company's catalog. The plan line is the seeded Product `InsuranceProductSlug::for(provider, code)`
  and must be published, so an un-granted line is not sellable.
- **The vehicle is a registered product.** The signed `insurance_vehicle_variant_id` must point to a non-deleted variant of the app
  whose product is typed `vehicle`, with `products.users_id` equal to the acting user (`InsuranceVehicleProduct`, also used by the
  validator). The signed variant is the order line, never a guess. Otherwise the contract is refused with a customer-safe message before any insurer call or order. No lead
  vehicle (search done with typed-in data) means "register your vehicle first". Vehicle products are never created implicitly.
- **An insurer is contractable only if it can emit and take a payment report** (`InsuranceContractGate`: its provider implements `PolicyEmissionProviderInterface` and `PaymentReportProviderInterface`). `insuranceSearch` exposes it as `is_contractable`, and both the cart validator and `contractInsurancePlan` refuse otherwise, so a customer is never charged for an insurer that would fail at the end. Chassis and plate are *not* gated here: the frontend requires `vin` and `plate` on the vehicle when the plan product's `insurance_requires_inspection` attribute is `1`.
- **Idempotent, closed-aware, retry-safe.** The Order carries `reference = insurance-lead-{lead id}`; a repeat call (or a retry
  after the Order was created but the Lead writes failed - Order is `commerce`, Lead is `crm` + custom fields in `ecosystem`, so
  no single transaction can span them) finds that Order, re-links the Lead (`insurance_order_id`) and sets it `Won`. A Lead no
  longer in an open status (`Lead::hasOpenLeadStatus`) is refused. `SelectInsurancePlanAction` only reuses an *open* lead.
- **Customer data.** Insurers declare what they need through the opt-in `CustomerDataProviderInterface`
  (`requiredCustomerFields()` + `customerBlock()`); the shape is canonical (`InsuranceCustomerFieldEnum`) and only the adapter
  knows its own field names. Values resolve as: `customer` input > Lead custom fields `insurance_customer_*` > the Lead's
  People profile (name, email, phone, dob). Anything supplied is stored on the Lead, so a retry only needs what is still
  missing. Missing fields fail with canonical names before any Order or payment exists.
- **Order shape.** Identical to the cart path (see "Order shape and the cart path"): item 1 the vehicle variant at price 0, item 2 the
  plan variant at the insurer total read at contract time, no tax/shipping/discount, `metadata.data.{quote_number, customer}`
  built by `InsuranceOrderMetadata::apply`, and no `metadata.insurance`. The insurer's tax stays in `insurance_tax`.
- **Workflow.** The Order `created` event fires from the action after the `insurance_*` fields are stamped. The order carries
  the `insurance` order type, so a rule can filter on it.

## Order type

Insurance is its own vertical, so its orders carry the order type `insurance` (`InsuranceOrderTypeEnum`), provider-agnostic.
That gives them the `OrderTransitionHistory` audit trail, order-type-filtered workflow rules, order-type-grouped stats and
card velocity protection.

- **Pipeline** (`InsuranceOrderStatusEnum`, seeded by `SetupInsuranceOrderPipelineAction`): `quoted` (default) ->
  `documents_uploaded` -> `awaiting_payment` -> `payment_reported` -> `emitted` -> `policy_active`, plus `cancelled` and
  `failed`. `policy_active`, `cancelled` and `failed` are final. The slugs are the `InsuranceStatusEnum` values, except
  `paid` -> `payment_reported`: a status slugged `paid` would make `TransitionOrderStateAction` stamp `payment_status = paid`
  while the charge is still an authorization, and a refused emission would leave a voided hold counted as revenue.
  `Order::markAsPaid` therefore finds no `paid` status and leaves the pipeline alone.
- **Setup, per environment and app:** `php artisan kanvas:insurance-setup-order-type {app_id}`. Idempotent. Without it,
  `contractInsurancePlan` is refused before the insurer or the customer is touched (`InsuranceOrderPipeline::isSeeded`), with an
  error in the log naming this command.
- **`InsuranceStatusEnum` stays the source of truth.** Every write of the `insurance_status` custom field goes through
  `RecordInsuranceStatusAction`, which sets the field and mirrors it into the order status through `TransitionOrderStateAction`.
  Never `$order->set(InsuranceCustomFieldEnum::STATUS, ...)` directly. The mirror is best effort: an order with no type (legacy), a
  different type, no current status, or a transition the pipeline does not allow is logged and skipped, and an exception is
  reported, never thrown, so it cannot break or undo the money path (no hold is released or voided because of a mirror failure).
- **Card velocity** can now be configured in `order_types.config.card_velocity` for `insurance`
  (`max_cards_daily`, `ban_cards_daily`, `corporate_*`). It is off until configured; nothing is set in code.
- **Legacy orders** created before the type existed (or by the FE with `metadata.insurance`) stay typeless and keep working;
  the mirror is a no-op for them.

## Order shape and the cart path

An insurance order (`order_type = insurance`) has two items, like the other verticals: the **vehicle** (a Kanvas
product typed `vehicle`, price 0, owned by the buyer through `products.users_id`) and the **plan** (the seeded product
`{provider}-{code}`, type `insurance-plan`, price = insurer total). Data with no home in items or order fields lives in
`metadata.data.*`, flat, with no `insurance` key:

```json
{ "data": { "quote_number": "355898", "customer": { "first_name": "Ana", "last_name": "Perez", "email": "...", "phone": "...",
  "birth_date": "1990-05-17", "sex": "female", "marital_status": "single", "document_type": "national_id",
  "document_number": "001...", "nationality": "...", "occupation": "...", "address_province": "...",
  "address_municipality": "...", "address_sector": "...", "address_building": "...", "address_street": "...",
  "politically_exposed": false, "has_pep_relative": false } } }
```

Lifecycle stays in the `insurance_*` custom fields. The FE sends `createOrderFromCart` with `order_type: "insurance"`,
`items: [{variant_id: <vehicle variant>, quantity: 1, price: 0}, {variant_id: <plan variant>, quantity: 1, price: <quote total>}]`
and `metadata.data.{quote_number, customer}`. `insuranceSearch` exposes `plan.variant_id` (the seeded product's variant, null
when the plan is not seeded/published) so the FE can build the plan line. Souk knows nothing about insurance.

- **The reader.** `ReadInsuranceOrderAction` returns an `InsuranceOrderData` (provider, product code, quote number, vehicle
  variant, plan variant, customer snapshot, legacy payload/cliente). Provider and product come from the plan item
  (`InsurancePlanProductResolver::identify`: the `insurance_provider`/`insurance_product_code` attributes stamped on the seeded
  product, falling back to matching `InsuranceProductSlug` against the local plans), then the `insurance_*` custom fields.
  A legacy order (`metadata.insurance` present) keeps reading that blob first and ignores `metadata.data`. The insurer-shaped
  request is rebuilt by `InsuranceOrderData::quoteRequest($provider)` (`provider->customerBlock(customer)`; the Universal
  adapter rebuilds `vehiculo` from the vehicle product only for the new shape (`InsuranceQuoteRequest::rebuildVehicle`); incomplete vehicle data (model id, year, fuel type, estimated value) is refused with a customer-safe reason about the vehicle. Legacy orders keep echoing the vehicle stored in the insurer's quote).
  `InsuranceProviderFactory::forOrder` prefers the stamped provider field, then the reader. Nothing else reads `metadata.insurance`.
- **Validation at creation = `AttachInsuranceQuoteActivity`.** Orders are generic, so there is no creation hook: the order is
  committed and the existing rule `Order created -> AttachInsuranceQuoteActivity` is the validate-and-attach step
  (`ValidateInsuranceOrderAction`). For a typed, non-legacy order it checks exactly one vehicle item (typed `vehicle`, owned by the
  order's user) and one plan item (resolving to an active plan of an active insurer, priced at the insurer total), no other items,
  quantity 1, the amounts that will be charged (the vehicle line priced 0, the order total equal to the plan price, no tax, shipping or
  discount; the payment is held for the order total while Report sends the insurer's total), `metadata.data.quote_number` bound to that
  user **and** plan by `InsuranceQuoteBinding` (an expired binding gets its own reason), the quote not already carried by another order
  with an insurance status other than failed/cancelled, and a customer complete for the insurer (canonical names only; `job_title` too
  when `politically_exposed`). Then it pushes the customer
  to the quote, re-reads it and compares the plan price (`ConfirmQuoteWithInsurerAction`, shared with `contractInsurancePlan`), and
  stamps QUOTED (`StampInsuranceQuoteAction` + `RecordInsuranceStatusAction`), plan id and the normalized `metadata.data` snapshot.
- **A failed validation cannot reject a committed order.** The activity sets `insurance_failure_reason` (customer-safe, canonical
  field names, never insurer text; the insurer's own text only goes in the workflow message), records `FAILED` (the order moves to
  the final `failed` status), releases any held payment and returns `failWorkflow`. Expected validation failures never throw or report. An unexpected throwable
  (provider or transport fault, anything raised before or inside `executeIntegration`) is caught, reported, recorded as `FAILED` with the
  generic unavailable reason and returned as `failWorkflow`; it is not rethrown (the order is already committed) and it does not void a
  hold by itself: a later Report on the unstamped order does, per the rules above. The FE reads
  `insurance_failure_reason` after the mutation returns. If a failed order still gets a held payment, `ReportInsurancePaymentActivity`
  finds no stamped quote number and voids the hold; nothing is captured.
- **The rule must be `is_async = false`.** For the cart path, `Order created -> AttachInsuranceQuoteActivity` has to be configured per
  environment and app with `is_async = false` (`ProcessWorkflowEventAction` runs a non-async rule inline through `SyncWorkflowStub`
  inside the request, after commit). The insurer calls take about 9 s each (PUT + re-read), so the creation request waits for them.
  If the rule is async the order is created unvalidated and stamped later; if it is missing the order stays unvalidated with no
  quote number, and a held payment is voided by Report. Neither charges the customer for a policy that was not validated.
- **Legacy and other types are untouched.** Orders without the `insurance` type, and orders carrying `metadata.insurance`, take the
  old Attach path (provider/quote from the blob, `executeIntegration`, exceptions propagate as before). Typeless orders without the
  blob are skipped. Customer fields beyond the legacy ones (address, due diligence) are required only on the new shape and on the
  `contractInsurancePlan` path, never for legacy orders.
- **Customer fields.** Added canonical `address_province/municipality/sector/building/street`, `politically_exposed`,
  `has_pep_relative`, `job_title`, `pep_relative_name/relationship/job_title`, all optional in GraphQL. Universal requires the address
  (building optional) and `politically_exposed`; nationality and country of residence are no longer required.

## Digital carnet and policy email

The carnet is the order-receipt mechanism, not a parallel one: `kanvas:insurance-setup-order-type` writes
`order_types.config.pdf_receipt` (template `insurance-carnet`, field `insurance_carnet_url`) and seeds the
`insurance-carnet` and `insurance-policy-issued` templates (from `resources/insurance/templates/`, never overwritten
once they exist). `GenerateInsuranceCarnetAction` runs `GenerateOrderReceiptAction` with the data from
`BuildInsuranceCarnetDataAction`, so the PDF lands in the order's `files` and its URL in `insurance_carnet_url`.
`SendInsurancePolicyEmailAction` is the single sender, used by `SendInsurancePolicyEmailActivity` and the
`sendInsurancePolicyEmail(order_id)` mutation (owner only; refused until the policy is active and the carnet exists).

- Both activities need a workflow rule per environment: carnet after the policy is invoiced, email after the carnet.
  A PDF failure is reported as a failed workflow step and never touches the insurance or order status.
- `insurance_policy_starts_at` / `insurance_policy_ends_at` (ISO dates) are shown on the carnet when present and omitted
  otherwise. Nothing fills them yet: a later increment will, once Universal's real response is known.

## Registered policies (bought outside MoviPass)

`registerInsurancePolicy(input)` lets a customer integrate a policy they already hold. `RegisterInsurancePolicyAction` creates an
Order of type `insurance` with `insurance_origin = registered` (`InsuranceOrderOriginEnum`); purchased orders carry no origin
field, so absence means bought through MoviPass. No new model: "Mis pólizas" is the same `orders` query filtered by the insurance order type.

- **One item, text insurer.** The only line is the vehicle variant (quantity 1, price 0, total 0). There is no plan item and no insurer
  relation because the insurer may not be one we integrate: `metadata.data.{insurer_name, plan_name, policy_number, starts_at, ends_at}`
  hold the plain text, flat, with no `metadata.insurance`. If that insurer is integrated later, link it then from this text.
- **Lifecycle.** Custom fields `insurance_policy_number`, `insurance_policy_starts_at` (optional), `insurance_policy_ends_at`,
  `insurance_status = policy_active` and the origin. The order goes `quoted -> policy_active` through `RecordInsuranceStatusAction`, so
  `OrderTransitionHistory` is recorded; that direct transition exists only since this feature, so **re-run
  `kanvas:insurance-setup-order-type {app_id}` per environment** (idempotent). Until then registration is refused
  (`InsuranceOrderPipeline::allowsRegistration`) with an error in the log naming the command.
- **Validation.** Non-empty trimmed text, real `YYYY-MM-DD` dates, `starts_at <= ends_at`; a past `ends_at` is allowed because expired
  policies are listed too. The vehicle variant must be a non-deleted `vehicle` product owned by the acting user in the acting app
  (`InsuranceVehicleProduct::variantOwnedBy`, shared with `contractInsurancePlan`).
- **Idempotent.** `reference = insurance-registered-{variant id}-{policy number lowercased, non-alphanumerics stripped}`; a repeat for
  the same user, vehicle and policy number returns the existing order.
- **No purchase flow.** No payment, quote, provider call or commission. Every purchase activity (`Attach`, `Report`, `UploadDocuments`,
  `Emit`, `Invoice`, `Sync`, `Cancel`, `GenerateCarnet`, `SendPolicyEmail`) returns `skipped` for a registered order through
  `InsuranceOrderOriginEnum::isRegistered`; a new purchase-flow activity must start with the same guard. We generate no carnet: the user
  uploads their own.
- **Documents** use the generic `attachFile` mutation on the order; there is no insurance upload path. Suggested `field_name` values:
  `policy_document` and `carnet_document`. They are not `InsuranceDocumentTypeEnum` values, so nothing is ever forwarded to an insurer.
- The order is created with `disableWorkflow()` and `disableNotifications()`, so neither the app-wide `Order created` rules nor the
  standard new-order emails fire. It is stored as `completed` / `fulfilled` with `payment_status` left `unpaid` on purpose: nothing was
  paid through MoviPass, and marking it paid would inflate payment stats.

## Policy expiry reminders

`kanvas:insurance-send-policy-reminders {--app=} {--date=YYYY-MM-DD}` reminds the order's user before the policy expires, over mail,
push and an in-app (`database`) notification. It is scheduled daily at 09:00 in `Kernel` (`onOneServer`), so the scheduler worker must
be running in every environment; `--date` simulates "today" and `--app` restricts it to one app. The command iterates every app that
has an `insurance` order type, binds each one with `overwriteAppService` before touching anything, and is safe to re-run.

- **Config.** `config/insurance.php` `reminders.days` (default `30, 15, 7, 3, 1, 0`, where `0` is the expiry day) and
  `reminders.channels` (notification slugs).
- **Selection.** `SendInsurancePolicyRemindersAction` reads only the order custom fields. One indexed query on `apps_custom_fields`
  for `insurance_policy_ends_at` in the handful of target dates, then `insurance_status = policy_active` and the marker for just those
  ids, then the orders (`fromApp`, `notDeleted`, insurance order type) in `chunkById(100)`. It never hydrates the app's orders.
  Registered policies always match; **purchased orders only match once a later increment fills `insurance_policy_ends_at` from the
  insurer**, so today they are simply not reminded.
- **Idempotency.** Order custom field `insurance_reminders_sent` holds comma-separated `{ends_at}:{days}` markers. Keying by the end
  date means changing `ends_at` never suppresses the reminders of the new date. The marker is written only after the notification is
  dispatched, so an order that failed is retried on the next run; failures are reported and do not stop the batch.
- **Content.** `InsurancePolicyReminderNotification` renders three seeded templates (`insurance-policy-reminder` for mail,
  `-push-title`, `-push-message`, the last also used for the in-app text). They are seeded by `kanvas:insurance-setup-order-type`,
  so **re-run it per environment** before the first scheduled run. The notification data carries `order_id` and
  `vehicle_variant_id` so the client decides where to send the user (comparator / renewal); no frontend URL is hardcoded, and the
  "alert on the vehicle" is something the client derives from that same notification.

## Open items

- Hold vs 3DS: `SettleInsurancePaymentAction` only touches an `AUTHORIZED` payment. The 3DS challenge flow (`startPaymentChallengeWithCard`) marks a held payment `PAID`, so insurance orders must be paid through `createPaymentMethod` → `addPaymentToOrder` → `processPayment` with `use_hold`, or a failed emission will not release the hold.
- None of the new activities has a workflow rule seeded; each env needs one per trigger.
