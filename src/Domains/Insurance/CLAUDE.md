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
Attributes. If a local plans catalog is ever built (insurer → plan → coverage →
exclusion, per `.planning/movipass/features/03_seguros`), *that* belongs there as
Products. It doesn't exist — `insuranceCatalog` is a passthrough to the insurer's API
and persists nothing.

**Why isn't this a connector?** Same reason `AgentRuntime` isn't. Per
[`Connectors/CLAUDE.md`](../Connectors/CLAUDE.md), a shared contract with N provider
implementations is a **primary domain**; the connector folders hold only the
per-provider implementations. Burying the contract inside one provider's folder leaves
the second provider with nothing to hang off.

So: contract, DTOs, enums, factory, actions and activities live here. The adapter lives
in its connector — `Connectors/UniversalSeguros/Providers/UniversalSegurosProvider.php`,
mirroring `Connectors/OpenClaw/Providers/OpenClawProvider.php`.

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
| Bind the quote, stamp premium/tax/total | `AttachInsuranceQuoteActivity` | order created |
| Push inspection documents | *(not wired)* | files attached to the order |
| Payment | *(undecided — see below)* | — |
| Emit the policy | *(not wired)* | payment completed |
| Poll the policy back | `SyncInsurancePolicyActivity` | schedule / status change |

`AttachQuoteToOrderAction` **re-reads prices from the insurer** rather than trusting
the client's quote payload, so a tampered payload can't set its own premium.

## Contracts

`InsuranceProviderInterface` is the only required one — `name()`, `integration()`,
`quote()`, `getQuote()`. `integration()` is what lets a single generic activity call
`executeIntegration()` for any insurer instead of one activity per brand.

Everything else is opt-in and checked with `instanceof` at the call site:
`InspectionProviderInterface`, `PaymentLinkProviderInterface`,
`PolicyProviderInterface`, `CatalogProviderInterface`,
`ProductCatalogProviderInterface`.

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
product is the unit the customer picks.

## Custom fields — hybrid on purpose

Shared concepts are generic (`InsuranceCustomFieldEnum`: provider, status, quote
number, policy number, payment url, premium, tax, total) so the FE reads the same keys
whichever insurer backs the order. Anything with no cross-insurer meaning stays on the
connector's own enum — for Universal that's `universal_seguros_request_id` and their
product code.

## Open items

- **Payment is undecided.** Either it goes through the Kanvas pipeline
  (`addPaymentToOrder` → `processPayment`, leaving a `payments` row to reconcile the
  aliado's commission) or it is delegated to the insurer's payment link.
  `PaymentLinkProviderInterface` is implemented for Universal but **not wired to
  anything** pending that call. Emission's trigger depends on the same decision, which
  is why emit isn't wired either.
- `InsuranceStatusEnum::PAID`, `CANCELLED` and `FAILED` are declared but nothing sets
  them — they land with the payment decision.
- The document-upload activity needs a trigger (files attached to the order) and
  should resolve paths from the Order's `Filesystem` attachments, never accept raw
  paths.
- `InsuranceQuoteRequest::$vehicle` is carried but unused: mapping a Kanvas vehicle
  product into the insurer payload is still the caller's job.
