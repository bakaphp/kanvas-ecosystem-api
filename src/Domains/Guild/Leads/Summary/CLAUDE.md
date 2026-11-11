# Rolling lead summary

Loads when work touches `src/Domains/Guild/Leads/Summary/`. Why an agent on a lead does not forget what
its history window dropped, and the rules that keep that true.

## The problem it solves

An agent on a lead reads the lead's rollup (`EntityRollupMessageStore`), cut to the history budget (50K by
default). The rollup never summarizes: its rows belong to other writers. Past the budget the oldest
messages (the trade-in, the lot staff chose, a promise) were cut silently. Regenerating a summary from the
trimmed transcript forgets exactly what the window forgets, so the summary here is **rolling and
coverage-tracked**: each update folds the next messages into the previous summary, and a marker records how
far it has read.

## Where it lives

One note per lead: the lead's `summary`-verb note (`LeadSummaryRepository`). `content` is the rendered text,
so `get_past_opportunities` keeps reading it; `lead_summary` holds the state (`LeadSummaryState`): sections,
items, `through` (highest counted message id read), `rebuild_from`. Content and state are one JSON column,
saved in one write, under the per-lead lock `lead-summary:{id}`.

## Which messages count

`LeadConversationMessages::counts()` is the one predicate: every channel, staff notes and AI Assist
included; never a summary note, a compaction note (`agent_summary`) or tool telemetry. A new reader of "is
the lead current" or "what goes into the summary" uses it, or the summary note sits above its own marker
and the lead never reads as current.

## A turn, in order (`HasKanvasAgentBehavior::resources()`)

1. Load the history, then `KanvasChatHistory::window()`: the loaded and retained row ids. Retained includes
   rows folded into a survivor. Past the fold cap a newer turn **replaces** an older one, which drops a row
   from the middle, so never reduce this to "the oldest kept row" (`LeadHistoryWindowTest`).
2. `PrepareLeadTurnMemoryAction`: `uncovered` = every counted id above the marker that is not retained,
   whether the rollup dropped it or never loaded it (a customer-facing rollup never loads staff notes).
   An update runs when that is non-empty, when a rebuild is pending, or when the summary plus what it
   does not cover passes 80% of the history budget and there is an older portion beyond the 8-message
   tail (D1). Nothing else triggers one: no timer, no count. `LeadHistoryWindow::withCoverage()` narrows
   that same set after the catch-up; it never recomputes it from `dropped`.
3. One chunk inside the turn, 15 s including the lock wait and the retry (D9). What is left goes to
   `ContinueLeadSummaryJob`, unique per lead until processing, which stops at its target. The summary
   provider is a closure resolved inside that step, never before: resolving it reads the agent's LLM
   setup and throws on an app with no key, which must cost a turn nothing when there is nothing to
   catch up and must not fail the reply when there is (`PrepareLeadTurnMemoryActionTest`).
4. The instructions get the summary block. A gap that survived step 3 is never silent:
   - internal agent: the date range and `read_lead_activity`;
   - customer-facing agent (that tool refuses customer surfaces): the missing messages verbatim, newest
     first up to 8K tokens, shown oldest first with speaker and date, plus "do not confirm what you cannot
     see". The lead owner gets one in-app notice per gap (`Cache::add` on lead + range).
5. The retrieval built after `resources()` reads the same window: memory recall leaves out an exchange only
   when both of its Social messages are retained (`inbound_message_id` from the kernel, `reply_message_id`
   stamped by `BaseAgentChannelReplyAction::createMessage()`). Unstamped and legacy entries always stay.

## An update (`UpdateLeadSummaryAction`)

- Neuron structured output into `Output\LeadSummaryUpdate`. Array items need `SchemaProperty(anyOf:)`:
  without it Neuron describes every array as a list of strings. `anyOf` takes classes only, which is why a
  citation is a `MessageCitation` object.
- `LeadSummaryUpdateValidator` checks meaning: item ids are open items of this summary, every citation is
  in this batch. **Any violation rejects the whole update**; the reasons go back for one more attempt.
  Applying the valid half would move the marker past the evidence for the rejected half.
- `LeadSummaryFold::apply()` copies items forward; only an operation changes one. Omission never deletes.
  `replace` closes the old item and links its successor; `keep` clears a "source changed" flag.
- Failure of any kind leaves the summary, items and marker as they were.

## Changed history

`LeadSummaryInvalidator`, from the Message and AppModuleMessage observers: an edited text, a deletion, or an
older message attached late (CRM sync, merge) at or below the marker sets `rebuild_from` and flags the items
citing it. Only the text counts (`content`/`text`/`message`/`body`); delivery-status rewrites change nothing.
It skips summary notes and telemetry before taking the lock: the summary note is saved under that lock.

## What the summary knows about the business

The six sections (`interest`, `budget_payment`, `location`, `appointments`, `objections`, `current_state`)
are the same for every tenant. What a business tracks beyond them is **configured, not coded**:
`agent_lead_summary_keys` (`AgentRunConfigurationEnum::LEAD_SUMMARY_KEYS`), a company setting that wins
over the app setting, is a JSON list of keys or `{key, description}` objects, or a comma-separated
string. The model reports each as a `key_facts` entry under that exact key. Name the keys after lead
custom fields (`vehicle_of_interest`, `trade_in`): `LeadSummaryContext` shows the lead record's value
for each configured key as known data, so the summary agrees with the CRM. The company profile
(`CompanyProfileService::promptBlock()`) rides along, so the model knows what business it is summarizing
without any setting. The summary never writes a custom field.

```php
// a dealer tenant; company setting wins over the app's, keys are normalised to snake_case
$company->set('agent_lead_summary_keys', [
    ['key' => 'vehicle_of_interest', 'description' => 'year, make, model and trim they want'],
    'trade_in',
]);
$app->set('agent_lead_summary_keys', 'policy_term, renewal_date');   // a comma string works too
```

Nothing set means the generic sections only. The model may still add a `key_facts` entry of its own
under a short snake_case key when a fact fits no section.

## Nothing to backfill

Summaries are built lazily, per lead, when its window first drops a counted message or passes 80% of
the budget. A note written with an older section layout is rewritten into the current one on its next
update (unknown keys render nothing in between), and `agent_lead_summary_keys` and the lead's custom
fields are read on every update, so configuring them later needs no rerun. No migration, no command.
The one bulk step on this feature is `intelligence:knowledge:prune-lead-messages`, per app, after D11.

## Switch, rollout, evaluation

- `agent_lead_summary_enabled` (`AgentRunConfigurationEnum::LEAD_SUMMARY`), per app, **on by default**; `0`
  turns it off for an app (Max, 2026-11-06). The D11 thresholds are watched on every tenant from deploy,
  not on one tenant first.
- `vendor/bin/neuron evaluation tests/Evaluations/LeadMemory` runs the production fold against the real
  model (`GEMINI_API_KEY`). The four committed scenarios are synthetic; add anonymised real transcripts
  before widening the rollout (`datasets/README.md`).
- A lead with a rolling summary is skipped by the closed-lead `SummarizeLeadConversationAction`.

Plan and decisions: `docs/intelligence/agent-lead-memory-and-knowledge-plan.md`.
