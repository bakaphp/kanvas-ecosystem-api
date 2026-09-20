# TypeSafe (Jev / System One) — connector

Loads when work touches `src/Domains/Connectors/TypeSafe/` or any call site that asks Jev a question.
Full rollout plan (PR-by-PR, with the intended call sites): `docs/connectors/typesafe-jev-plan.md`.

Jev takes a `state` plus named typed questions and returns **calibrated** answers. It never generates
text. `POST https://api.typesafe.ai/v1/systemone`, Bearer key, 70–500 ms, $0.042/Mtok in, output free.

## What's here (PR1 — connector only, no call sites)

| | |
|---|---|
| `Client` | `ask(state, questions, model?)`, `models()`, `static validateCredentials(key)` |
| `DataTransferObject/` | Questions `Noul` / `Choice` / `Score`; answers `NoulAnswer` / `ChoiceAnswer` / `ScoreAnswer`; `SystemOneResult` |
| `Enums/ConfigurationEnum` | `TYPESAFE_API_KEY`, `TYPESAFE_MODEL`, `TYPESAFE_DECISIONS` — app settings |
| `Enums/DecisionModeEnum` | `OFF` / `SHADOW` / `LIVE` |
| `Services/TypeSafeConfigService` | the only place that reads those settings; `decisionMode(string)` |
| `Handlers/TypeSafeHandler` | `integrationCompany` setup; writes the key to the **app** |

Nothing calls it yet, by design — adding the connector changes no behaviour anywhere.

## The architecture, in one line

**System One decides; the existing LLM stays as the fallback; code owns the thresholds.** There is no
provider-agnostic `DecisionEngine` abstraction and there should not be one until a second System One
vendor exists — each call site's fallback is simply its current code.

## Rolling out a decision

Every call site is gated by an app setting, `TYPESAFE_DECISIONS`, a map of decision key → mode:

```json
{ "signal_routing": "shadow", "contact_checker": "off" }
```

- **OFF** (also: no API key, missing key in the map, unreadable value) — today's behaviour exactly.
- **SHADOW** — run the LLM path and act on it; also ask Jev and record both. Jev must never affect the
  outcome, and a Jev failure is swallowed (logged, **not** `report()`ed).
- **LIVE** — act on Jev at or above the decision's threshold; below it, or on any exception, use the LLM.

Resolve it with `new TypeSafeConfigService($app)->decisionMode('signal_routing')` — never by reading
the setting yourself.

Exit criteria to move a decision from SHADOW to LIVE: ≥2 weeks of shadow data, agreement ≥90% above
the new threshold, and a manually-labelled sample of the disagreements showing Jev is no worse.
**Thresholds are per decision *and per language*** — see the language rule below.

## Hard rules — these are what the jaggedness page costs us if ignored

1. **Numbers, math, counting and date comparison stay in code.** Jev picks among candidates code has
   already computed. Filter by amount and date window in SQL, then ask Jev which of the shortlist
   matches semantically.
2. **It reads literally.** Put boundary cases in `criteria`. One judgement per question; combine in
   code. A Noul that asks two things at once returns a number that looks calibrated and is not.
3. **Irrelevant state lowers accuracy.** Send only the fields the question needs. Dumping the whole
   lead is not a free convenience, it is a measurable accuracy loss.
4. **Inbound text is adversarial.** Never let a single Jev answer take an irreversible action —
   consent/do-not-contact, a record merge, a payment, an outbound send. High confidence flags it for a
   human; it does not do it.
5. **No text generation.** Anything needing a `reason` or `internal_notes` string stays on an LLM, or
   becomes a template built from the answers.
6. **English is best; a large share of our traffic is Spanish.** Measure agreement per language and set
   the LIVE threshold per language. Spanish may stay in SHADOW while English goes live. Detect the
   language in code — do not ask Jev for it and then act on it in the same breath.
7. **No structural invariants.** A threshold tuned on a Noul does not carry to a Choice. P(x) + P(not x)
   ≠ 1 across two Nouls. A Choice's `confidence` and a Noul's probability are not the same quantity.

### Noul vs Choice — the distinction that bites

A **Choice is relative** ("which of these wins") and always returns something, so give it an explicit
`none` / `unclear` option whenever "none of these" is a real outcome. A **Noul is absolute** ("does this
apply"), which is what multi-label tagging needs: one Noul per vocabulary term in a single request, not
one Choice over the vocabulary. And a Noul carries **no `confidence`** — only the probability. Gate on
that number directly.

## Foot-guns in this code

- **`TYPESAFE_MODEL` is pinned to `jev-1.13.0`, not `jev-latest`.** The alias moves when TypeSafe ships,
  and every threshold we tune is tuned against one version's calibration. Moving it is a deliberate PR
  that re-runs the shadow comparison. `SystemOneResult::$model` is the version that actually answered —
  record it with every shadow row.
- **Short timeouts and a capped backoff are the point.** The client retries 429/529 up to 3 attempts and
  honours `retry-after` only up to 1.5 s. A System One call that takes seconds is worse than no call at
  all, because the fallback is sitting right there.
- **Everything throws `TypeSafeException`** (extends `ValidationException`). One class on purpose: a call
  site's contract is "no answer → use the LLM", and it should not have to enumerate failure modes.
- **A missing numeric field is an error, not a zero.** `SystemOneResult` throws rather than defaulting a
  missing `noul` to 0.0 — that would read as a confident "no".
- **Score levels are array positions starting at 0.** `Score` rejects a non-list for exactly that reason.
  The returned `score` is a probability-weighted average that lands *between* levels (1.43); it is a
  rubric position, not a quantity — do not sum or average it.
- **Choice options cap at 255.** Shortlist in code first; the constructor throws rather than letting the
  API reject it.
- **The DTOs are plain objects, not `Spatie\LaravelData\Data`.** Deliberate: shadow-mode call sites will
  hand these to queued jobs, and a `Data` subclass holding models flattens to primitives that never
  restore (root `CLAUDE.md`, "Never Queue a Spatie Data DTO"). Do not convert them for `::from()` magic.
- **`TypeSafeHandler::setup()` only writes `TYPESAFE_MODEL` when the form supplies one.** Re-running setup
  to rotate a key leaves that field blank; writing the default there would silently retune a tenant.

## Not a fit

Text generation (replies, notes, titles), image/PDF input (OCR first), arithmetic and date logic, and
deterministic lookups that have no LLM today (`LeadIntentTool` maps from source config — leave it alone).

## Still open

- **The DPA is not accepted.** https://typesafe.ai/legal/data-processing — someone has to accept it
  before real tenant data (lead messages, invoices) goes to TypeSafe, even in SHADOW. Zero data
  retention is enterprise-only.
- **The shadow log table does not exist yet.** It belongs in the Intelligence/NervousSystem domain, not
  here — a connector must not own a table the platform reads (see `../CLAUDE.md`). It lands with the
  first call site.
- **Early access pricing may be subsidised and rate limits can change without notice** (TypeSafe says
  so). The fallback must always work.
