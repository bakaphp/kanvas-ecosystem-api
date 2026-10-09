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
directly. They are native models in `Models/` on the `commerce` connection.
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

The Order is created carrying `metadata.insurance.{provider, quote_number}`. From
there:

| Step | Runs as | Trigger |
|---|---|---|
| Bind the quote, send the customer, stamp premium/tax/total | `AttachInsuranceQuoteActivity` | order created |
| Push inspection documents | `UploadInsuranceDocumentsActivity` | attach-file |
| Report the payment we took | `ReportInsurancePaymentActivity` | card authorized |
| Emit the policy, capture the hold | `EmitInsurancePolicyActivity` | payment reported |
| Invoice the policy | `InvoiceInsurancePolicyActivity` | policy emitted |
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
`CancelInsuranceOrderActivity` voids the hold and sets `CANCELLED`, and refuses once a policy exists
(that is a refund, not a void). `SettleInsurancePaymentAction` only touches an `AUTHORIZED` payment.

## The customer

Universal validates the customer and chassis on the quote before it can be emitted (§4.3 step 1), so the
order carries the customer under `metadata.insurance.cliente` (the insurer-shaped block) beside
`metadata.insurance.product` and `metadata.insurance.payload`. `AttachQuoteToOrderAction` PUTs it onto the
quote through the opt-in `QuoteUpdateProviderInterface` *before* re-reading the prices, so the stamped
totals are the final ones. The PUT replaces the whole quote, which is why `payload` has to be stored too.

## Documents

Files are typed by the attachment's `field_name`, which must be an `InsuranceDocumentTypeEnum` value
(`registration`, `inspection_video`, `identification`); anything else on the order is never forwarded.
Already-sent filesystem ids live in `UPLOADED_DOCUMENTS`, because attach-file fires on every
attachment. Emission is refused for an inspection-required product until something has been sent.

## Open items

- `InsuranceQuoteRequest::$vehicle` is carried by `BuildQuoteRequestFromOrderAction` but no adapter reads it yet.
- Hold vs 3DS: `SettleInsurancePaymentAction` only touches an `AUTHORIZED` payment. The 3DS challenge flow (`startPaymentChallengeWithCard`) marks a held payment `PAID`, so insurance orders must be paid through `createPaymentMethod` → `addPaymentToOrder` → `processPayment` with `use_hold`, or a failed emission will not release the hold.
- None of the new activities has a workflow rule seeded; each env needs one per trigger.
