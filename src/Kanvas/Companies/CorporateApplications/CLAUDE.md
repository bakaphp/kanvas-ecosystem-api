# Corporate Applications

Loads when work touches `src/Kanvas/Companies/CorporateApplications/`. Generic, app-agnostic
"a company asks for corporate status and an admin decides" flow. Movipass is the first consumer;
nothing in this folder may import from `Kanvas\Connectors\*`.

## What it is — and what it is not

A **corporate application is a `Lead`**. There is no table, model, migration or DTO for it: the
application state lives in the Lead's custom fields (`CorporateApplicationFieldEnum`), and the
review queue is the ordinary `leads(hasCustomFields: …)` query. Do not add a model here — the whole
point of the design is that the CRM already retains, lists, assigns and audits the row.

Two entry paths produce the same Lead shape:

| Path | Creates the Lead | Marks the status |
|---|---|---|
| Webhook form (`POST /v1/receiver/{uuid}` on the corporate receiver) | `CreateLeadsFromReceiverJob` | `AutoApproveCorporateLeadActivity` (Movipass) on Lead `created` |
| Self-service upgrade (`enableCorporateMode` mutation) | `EnableCorporateModeAction` (Movipass) | same action, status `pending` |

Both end in `approveCorporateApplication` / `rejectCorporateApplication` (`@guardByAdmin`,
`app/GraphQL/Ecosystem/Mutations/Companies/CorporateApplicationMutation.php`).

## How a request is created

There is no "create application" mutation. The Lead is created by one of the two paths:

- **Webhook form** — `POST /v1/receiver/{uuid}` on the corporate receiver with the application keys as
  `custom_fields` (`legal_name`, `commercial_name`, `rnc`, `contact_name`, `contact_email`, …).
  `CreateLeadsFromReceiverJob` stores whatever arrives; the Lead `created` workflow rule then runs
  the triage activity, which stamps `corporate_application_status`: `pending` (manual mode, keeping
  a validation hint if the RNC is malformed), or in auto mode approves on the spot / marks
  `needs_review` when validation fails.
- **Self-service upgrade** — the authenticated `enableCorporateMode(input: CorporateOnboardingInput!)`
  (Movipass, same keys + `region_id`). `EnableCorporateModeAction` checks the receiver's required fields and the format rules,
  creates the provisional Company (no `is_corporate`), grants the admin role, runs onboarding, and
  files the Lead on the corporate receiver as `pending` with `UPGRADE_USER_ID` /
  `UPGRADE_SOURCE_COMPANY_ID`. It refuses a user who is already corporate or already has a pending
  request in that app.

The admin sees both in `leads(hasCustomFields: { NAME = corporate_application_status, VALUE = pending })`.

## Nothing happens unless the app is set up

The feature is data-activated per app; the code is inert without three things:

| Missing | Symptom |
|---|---|
| A corporate receiver: setting `corporate_application_receiver_id` (legacy `movipass_corporate_receiver_id`), or a receiver carrying `approval_mode` | `enableCorporateMode` throws "Corporate onboarding is not configured"; leads from any other receiver are skipped (`resolveFor()` → `null`) |
| Workflow rule `Lead / created → AutoApproveCorporateLeadActivity` | webhook leads never get a status, never reach the queue |
| Workflow rule `Lead / corporate-application-approved → SetupApprovedCorporateCompanyActivity` (+ the parking publisher where relevant) | approval sets `is_corporate` and switches the user but assigns no region, migrates no variants, publishes nothing |

Plus the mode, `corporate_application_auto_approve` (legacy `movipass_corporate_auto_approve`), which
a receiver's `approval_mode` overrides. `kanvas:movipass-setup-parking-application-rules {app_id}
--receiver={id}` wires all of it for Movipass. The `approve`/`reject` mutations exist in every app,
but only act on leads that already carry the status field.

## Which fields an application needs is configured on the LeadReceiver

A `LeadReceiver` is a pipe: it stores every `custom_fields` key it receives and validates none. What
a given receiver's applications must carry, and what gets copied onto the Company and the User, is
configuration **on that receiver**, as custom fields next to `approval_mode`:

| Receiver custom field | Default when absent | Read by |
|---|---|---|
| `application_required_fields` | `legal_name`, `rnc`, `contact_email` | `Field::requiredFor($receiver)` |
| `application_company_fields` | `legal_name`, `commercial_name`, `rnc` | `Field::companyFieldsFor($receiver)` |
| `application_user_fields` | `contact_name`, `contact_role`, `contact_email`, `contact_phone` | `Field::userFieldsFor($receiver)` |

Values are a JSON list or a comma-separated string; an empty list falls back to the default. So a
receiver for a different kind of account (a parking company, a fleet) declares its own keys with no
code change, and the flow enforces them: `ApproveCorporateApplicationAction` refuses to approve while
any required key is empty (`Cannot approve: missing …`), `EnableCorporateModeAction` rejects the
request, and the triage activity parks the lead in `needs_review` with the missing keys as the
reason. Format rules that are not "is it present" (RNC digits, email shape) stay in
`ValidateCorporateFieldsAction` — presence is data, shape is code.

Two things the receiver config does **not** do: it does not make the receiver reject a POST (decision
5 — the receiver never validates; the reviewer does), and it does not add new columns to the Company —
a key listed in `application_company_fields` lands as a custom field on the Company.

## Approval is manual by default, and `is_corporate` is the only privilege switch

`is_corporate` on the Company (and the User) is what unlocks corporate behaviour downstream
(Paso Rápido corporate limits, TAG access through corporate companies, RNC on invoices). Only
`ApproveCorporateApplicationAction` sets it. The request paths provision a Company so the user has
somewhere to land, but never set the flag — RNC and phone are self-reported and were being faked.

`CorporateApplicationApprovalModeEnum::resolveFor()` decides manual vs auto:
1. the receiver's `approval_mode` custom field wins (`manual` | `auto`; anything else is ignored),
2. otherwise the app settings (`corporate_application_auto_approve`, legacy `movipass_corporate_auto_approve`),
3. a lead whose receiver is not the configured corporate receiver is not an application at all (`null`).

## Approval has two halves — the second needs a workflow rule

`ApproveCorporateApplicationAction::execute()` does the synchronous half (Company + admin role +
onboarding + invite on the new-account path; `is_corporate` + company switch on the upgrade path),
then fires `WorkflowEnum::CORPORATE_APPLICATION_APPROVED` on the Lead. **Everything that runs after
that is a workflow rule on `Lead / corporate-application-approved`** — region assignment and the
variant migration (`SetupApprovedCorporateCompanyActivity`), parking publication, etc. Without the
rule, approval looks complete and silently does nothing else. The rule is data, not code: wire it
per app (`kanvas:movipass-setup-parking-application-rules {app_id}` does it for Movipass) and check
it exists before debugging "approved but nothing happened".

The upgrade's source company is persisted on the Lead at request time
(`UPGRADE_SOURCE_COMPANY_ID`), never read from the user at approval time: they are separate requests
and the user may have switched companies in between.

## Keys: new names with a legacy fallback

Every field and setting has a `corporate_application_*` name and a `movipass_corporate_*` legacy
twin (`legacyKey()` / `readFrom()` on the enums). Reads go through `readFrom()`; writes use the new
key only. Applications filed before the flow left the Movipass connector still carry the legacy
keys, so a queue filter has to include both until they are backfilled. Do not add a third naming.

## Gotchas that already cost a bug

- **Roles are Bouncer-scoped by the request.** The admin-role lookup must be
  `RolesRepository::getByNameFromApp(...)`, not the `FromCompany` variant: from the GraphQL mutation
  the Bouncer scope is the admin's current company, not `company_0` where roles live, and the
  company variant throws `No query results for Role`. `CorporateApplicationApprovalTest::setUp`
  sets that scope on purpose so the test fails the way production would.
- **Scope every lookup by app.** `hasPendingRequest()`, the invite-hash lookup and the receiver
  lookup all take the app; a pending request in app A must not block app B.
- **Custom-field queries and test transactions.** `FlagOverdueCorporateApplicationsAction` plucks
  the entity ids through the `AppsCustomFields` model (the `ecosystem` connection) and filters Leads
  in PHP. Rewriting it as a cross-database `whereIn` subquery run from the `crm` connection reads a
  different PDO handle, so inside a `DatabaseTransactions` test it cannot see rows the same test
  just wrote on `ecosystem` — it looks like a bug in the action and is not.
- **`SendsApplicationEmail` requires an entity** because `Blank` does; every caller has a Lead.
- Rejection always notifies the applicant (`corporate-rejected` fallback template); rejecting an
  already-approved application throws; rejecting a pending *upgrade* releases the provisional
  Company and de-associates the user, the source company is untouched.

## Tests

`tests/Ecosystem/Integration/Companies/*` run in CI. The end-to-end approval tests still live under
`tests/Connectors/Integration/Movipass/` (`CorporateApplicationApprovalTest`,
`EnableCorporateModeActionTest`, `AutoApproveCorporateLeadActivityTest`) and are skipped on
`GITHUB_ACTIONS` — run them locally, one file per phpunit process, before touching this folder.
