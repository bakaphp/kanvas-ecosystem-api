# Movipass — Asistencia Regional (El Salvador) roadside model

Loads when work touches `src/Domains/Connectors/Movipass/`. Spec: "MoviPass El Salvador — Flujo
funcional completo de Asistencia Vial", v6.0 (Aug 2026).

## The gate

Everything regional asks `Support\RegionalAssistance::isEnabled*()` first
(`movipass_roadside_regional_assistance`, company setting wins over app). Other tenants run the
shared roadside flow untouched; `RoadsideAuditTrail::forOrder()` is a no-op for them.

## Two state layers — don't merge them

- **Order status** (DB, `RoadsideAssistanceStatusMap` → `kanvas:movipass-setup-roadside-assistance`,
  idempotent): the coarse statuses the shared flow drives (`awaiting_operator`, `dispatched`, ...).
- **Homologated state** (`assistance_case.state`, `Enums\RoadsideCaseStateEnum`, spec §12): the
  regional machine. Only `TransitionRoadsideCaseStateAction` writes it; it enforces `next()`, the PIN
  guard for `IN_PROGRESS`, the evidence guard for `COMPLETED`, a reason for `NOT_RESOLVED`/`CANCELLED`,
  appends `state_history`, audits, queues the provider event, and notifies only for states the shared
  flow does not already push (no double notifications). Order-status changes are mirrored by
  `SyncRoadsideCaseStateFromOrderStatusAction` from the activity's STATUS_TRANSITION branch.

`ADDITIONAL_PAYMENT_PENDING` and `INCIDENT` interrupt any active stage and resume it via
`interrupted_state`.

## Lifecycle

1. Intake (`Support\RoadsideIntakeQuestionnaire` = what is asked, `Support\RoadsideIntakeRules` =
   discard rules → proceed / cabin_review / not_proceed). Pricing refuses `not_proceed`.
2. Payment guard (`ProcessPaymentAction`): quoted total, terms accepted, required photos.
3. `DispatchRegionalRoadsideCaseAction` runs when **both** the case is prepared (`prepared_at`) and the
   order is paid — the payment and the CREATED workflow land in either order, both call it.
4. `OfferRoadsideCaseAction`: offer rounds to `Services\RoadsideTechnicianPool` (available, app member,
   authorized service, cabin shortlist, minus rejecters/cancellers). `ExpireRoadsideOfferJob` re-offers.
5. APK: accept/reject, tracking (EN_ROUTE → NEARBY → ON_SITE), PIN, evidence, close
   (`RoadsideRegionalMutation`). The regional close skips the shared flow's user confirmation.

## Where things live

- Tariffs: launch defaults in `RoadsideTariffSchedule`; changes are appended versions
  (`movipass_roadside_tariff_versions`, `UpdateRoadsideTariffsAction`) picked by order `created_at`.
- Audit: the NervousSystem ledger (append-only), `source_domain = movipass.roadside`. No table here.
- Provider sync: `Support\RoadsideProviderOutbox` (custom field, not the case — the case is rewritten
  wholesale and would drop entries) + `Jobs\SyncRoadsideRegionalEventJob` (retries, `failed()` audits).
  The provider API only has state and contact endpoints; other event types are recorded `skipped`.
  Never invent a provider field mapping — the contact body is `assistance_case.provider_contact`.
- SLAs: `Support\RoadsideSlaCalculator` reads `state_history`; `kanvas:movipass-roadside-sla-check`
  flags breaches (needs scheduling).

## Traps

- `assistance_case` is stored twice (root and `data`) and rewritten whole. Always go through
  `InteractsWithAssistanceCase`, re-read after calling another action, and never save a copy you read
  before that call.
- `PrepareRoadsideAssistanceCaseAction` rebuilds the case from known keys only; a new client-sent key
  is dropped unless you carry it through there.
- `Order::transitionToStatus()` silently ignores an undeclared transition — add it to
  `RoadsideAssistanceStatusMap` and re-run the setup command.
- Distance and geofences: `Baka\Support\Geo` is the one haversine; don't add another.
