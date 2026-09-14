# Customers — Consent & Do-Not-Contact

Loads when work touches `src/Domains/Guild/Customers/`. The rest of the domain (People, Contact,
merge, duplicates) is ordinary CRUD; this file is only about the consent system, because getting it
wrong is a regulatory problem rather than a bug.

## The rule

**A stop request is person-wide and channel-wide.** STOP over SMS silences WhatsApp and email too,
across every lead that person has. There is no per-channel opt-out lane and there must not be one.

Why it is not a preference:
- FCC (47 CFR 64.1200, effective 2025-04-11) requires honoring `STOP UNSUBSCRIBE END QUIT STOPALL
  REVOKE OPTOUT CANCEL`, and its revoke-all rule — one channel's revocation binding every channel —
  lands 2027-01-31.
- WhatsApp's Business Messaging Policy **already** requires honoring requests made "on or off
  WhatsApp", so cross-channel is not optional there today.
- The FCC also permits **exactly one** post-revocation message. Two is the violation.

`People` is `apps_id` + `companies_id` scoped, so person-wide stays tenant-bounded by construction:
opting out at one dealer does not opt out at another.

## The pieces

| Concern | Class |
|---|---|
| Is this message a consent signal? | `Services/ConsentKeywordService` |
| Apply a stop request | `Actions/ApplyDoNotContactAction` |
| Apply a START / re-subscribe | `Actions/RevokeDoNotContactAction` |
| Detect + dispatch, for an inbound webhook | `Actions/ProcessInboundConsentAction` |
| The one permitted acknowledgement | `Guild\Leads\Actions\SendOptOutConfirmationAction` |
| Natural-language stop requests | `Intelligence\...\Tools\CRM\StopContactTool` |
| Inbound mail (both receivers) | `Connectors\Mailgun\Services\MailgunConsentService` |

State written by an opt-out, keyed by `Enums/ConsentConfigurationEnum`:
- every `Contact` of the person → `is_opt_out = 1`
- the `People` row → `do_not_contact` (+ reason / at / source)
- every existing `Lead` of that person → the same, plus `ai_mode = idle` **and**
  `lead_ai_mode_is_manual = true`

**The manual pin is not optional.** `Lead::resolveAiMode()` lets the lead-type config outrank the
stored mode unless that flag is set, so setting `ai_mode` alone leaves a silenced lead free to start
replying again.

**The people-level flag is not redundant with the lead-level one.** Flagging leads covers the ones
that exist at the time; a connector sync can create a *new* lead for the same person afterwards, and
it carries no flag. Every guard therefore checks both.

## Where it is enforced

| Guard | Covers |
|---|---|
| `SendMessageToLeadAction::guardDoNotContact()` | called once in `execute()`, ahead of the channel dispatch — so it covers SMS, WhatsApp, RespondIO, email and voice together |
| `SendMessageToLeadAction::guardDestinationOptOut()` | per-address opt-outs a stop request never produced (a DMS `doNotEmail` push, a hard bounce) |
| `LeadOutboundChannelResolver::resolve()` | follow-up returns no channels for a flagged lead |
| `AgentReachOutAction` | cold outreach skips a flagged lead |
| `SendEmailTool` / `SendSmsTool` | agent-initiated sends |

The single `execute()`-level guard is deliberate: a per-channel `if` in each send method is how one
channel ends up forgotten. `sendWhatsAppMessage` hands off to `sendRespondIoMessage` **before** its
own per-address check, which is exactly that failure mode — RespondIO needs its own.

## Three tiers, and only the last one is a tool

Detection runs in `ProcessInboundConsentAction`, in descending order of confidence. **The first two
are deterministic and run on message arrival, before any agent turn is dispatched** — a tool call is
optional, and a compliance control cannot be.

| Tier | What it matches | On a hit |
|---|---|---|
| 1. Exact keyword | the whole message is an FCC keyword | apply, `match: exact` |
| 2. Phrase | a do-not-contact sentence inside a longer message | apply, `match: phrase` |
| 2b. Narrowed phrase | tier 2 **plus** a scope qualifier | flag nothing, note for a human, agent still answers |
| 3. `stop_contact` tool | anything the patterns missed | apply, `match: agent_tool` |

Tier 1 matches the whole value only, so "stop by the dealership tomorrow" is not an opt-out.

Tier 2 is the promoted version of what used to be `AgentReachOutAction::descriptionRequestsNoContact()`
— a private matcher that only ever read the lead description. It now runs on inbound messages too, and
`AgentReachOutAction` calls the shared one. Do not add a third copy.

**Tier 2b is the part that is easy to get wrong.** A phrase match is an inference, and two shapes of
message trip it while asking us to keep talking: a channel swap ("don't email me, text me instead") and
a time window ("don't call before 5pm"). Opting either out of everything silences a live customer.
`narrowsRequestScope()` catches both, and the result flags nothing.

Tier 3 stays because the FCC requires honoring a revocation made in "any reasonable manner", and
Twilio explicitly does **not** block non-keyword phrasing on our behalf. It is the net for phrasing no
pattern anticipated, and for intent that only makes sense in conversation ("yeah, go ahead" answering
"would you like me to stop?"). **Any agent that talks to prospects still needs `stop_contact` in its
toolset and the compliance line in its instructions** — it is no longer load-bearing, but it is the
only tier that sees the thread rather than one message.

Every applied opt-out records which tier caught it in `do_not_contact_match`, so a human reviewing a
`phrase` flag can reverse a wrong call. The lead note says so in words too.

## Foot-guns

**Don't rename `do_not_contact`.** Leads flagged before this system existed wrote that exact key, and
`SendEmailTool`, `SendSmsTool`, `BatchRecipientResolverService` and `ProcessLeadCampaignJob` all read
it. A rename silently un-flags every existing opt-out. The enum exists to stop the string drifting,
not to change it.

**Never bypass the guard except for the acknowledgement.** `allowOptOutConfirmation()` exists for
`SendOptOutConfirmationAction` and nothing else. Anything else reaching for it is a compliance bug.

**Don't send our own SMS acknowledgement when Twilio sends one.** `twilio_sends_opt_out_reply`
defaults to **true** for that reason. Sending zero acknowledgements is legal; sending two is not.

**Re-subscribe is narrow on purpose.** `RevokeDoNotContactAction` opts back in only the address the
START came from. A blanket opt-in would clear opt-outs the person never revoked here — a hard bounce,
a DMS-pushed `doNotEmail` — and reopen channels they never asked to reopen. Wide to silence, narrow to
resume.

**`HandOffAction` is not a neutral way to get a human's attention.** `COMPLIANCE_INTERNAL` calls
`optOutPhoneContacts()`, and *every* handoff type sets `agent_hand_off`, which terminally exhausts
follow-up. That is correct on a real opt-out and destructive on a narrowed request — the first version
of tier 2b used it and silently opted out the phone of a customer who had only asked to be texted
instead. Covered by `test_a_channel_preference_is_not_treated_as_an_opt_out`. If you want to tell a
human something without changing state, record a note.

**Email has TWO inbound receivers and both need wiring.** `AgentProcessEmailWebhookJob` is the shared
lead inbox and `AgentInboxWebhookJob` is one agent's own address — the second replies *inline* rather
than through a workflow rule, so it was the easier one to miss. Both go through
`MailgunConsentService`, which adds the subject pass email needs: a one-word "Unsubscribe" lands in the
SUBJECT at least as often as in the body, usually with nothing under it.

**Order matters on the lead inbox.** `CreateMessageFromEmailAction` is what fires the responder
workflow, so consent is evaluated *before* it and passes `suppressAgentResponse`. Evaluating it after
let the agent read a stop request, spend a turn on it, and compose a reply the outbound guard then
threw away.

**There is no public unsubscribe endpoint, and that is deliberate.** A `List-Unsubscribe` header needs
a URL anyone on the internet can POST to, and we are not exposing one. Email opt-out arrives the same
way every other channel's does — through the inbound webhook, where `unsubscribe` is already a tier-1
keyword. If deliverability ever demands the header, the `mailto:` form of `List-Unsubscribe` routes to
the existing Mailgun inbound and needs no new surface.

**The opt-out commits before the audit trail.** Notes, the compliance handoff and the ledger write all
run outside the transaction and are individually best-effort: they reach other connections and other
services, and none of them failing is a reason to leave the person contactable.
