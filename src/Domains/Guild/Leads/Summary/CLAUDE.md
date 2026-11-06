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
2. `PrepareLeadTurnMemoryAction`: `uncovered` = dropped counted ids above the marker. An update runs when
   that is non-empty, or when the summary plus what it does not cover passes 80% of the history budget and
   there is an older portion beyond the 8-message tail (D1). Nothing else triggers one: no timer, no count.
3. One chunk inside the turn, 15 s including the lock wait and the retry (D9). What is left goes to
   `ContinueLeadSummaryJob`, unique per lead until processing, which stops at its target.
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

## Switch, rollout, evaluation

- `agent_lead_summary_enabled` (`AgentRunConfigurationEnum::LEAD_SUMMARY`), per app, off by default. Shepard
  Auto (app 2) first, at least a day (D5); further only when the D11 thresholds hold.
- `vendor/bin/neuron evaluation tests/Evaluations/LeadMemory` runs the production fold against the real
  model (`GEMINI_API_KEY`). The four committed scenarios are synthetic; add anonymised real transcripts
  before widening the rollout (`datasets/README.md`).
- A lead with a rolling summary is skipped by the closed-lead `SummarizeLeadConversationAction`.

Plan and decisions: `docs/intelligence/agent-lead-memory-and-knowledge-plan.md`.
