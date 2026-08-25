# WaSender Connector (WhatsApp)

Inbound WhatsApp for Kanvas. One webhook job routes **three conversation shapes** to three sibling
actions; everything else here is about telling them apart and configuring them.

## The three shapes

`ProcessWaSenderWebhookJob::handleMessageUpsert` is a router. In order:

1. `InboundMessage::fromWebhookMessage()` — unroutable payload → silent skip (an expected condition,
   never `report()`).
2. Newsletter (`@newsletter`) → skip. Broadcast-only, nobody to answer.
3. `isFirstDelivery()` — 10-minute cache dedupe on the WhatsApp message id. WaSender documents **no**
   delivery or ordering semantics, and with the reply running inline a redelivery is a second answer.
4. **Group** (`@g.us`) → `CreateGroupMessageAction` — gated by `allowed_group_jids`.
5. **Assistant 1:1** → `CreateDirectAgentMessageAction`, when `CreateDirectAgentMessageAction::appliesTo()`
   says so.
6. **Everything else** → `CreateLeadMessageAction` — the historical lead flow.

| Shape | Entity | Creates a Lead? | Who replies |
|---|---|---|---|
| Lead DM (default) | `Lead` | yes | a **workflow rule** on `after-adding-message-to-channel` |
| Assistant DM | `Channel` | no | the burst job, **inline** |
| Group | `Channel` | no | the burst job, **inline** |

**A lead DM does not answer by itself.** It files the message, opens a Lead, and fires
`after-adding-message-to-channel` expecting a rule with an agent-responder activity to speak. A fresh
receiver with no such rule files messages and stays silent — that is configuration, not a bug. Assistant
and group conversations reply from `ProcessGroupBurstJob` and need no rule at all.

## Receiver configuration

Everything lives in `receiver_webhooks.configuration` (JSON). There is deliberately **no GraphQL
surface** — a `whatsappConfigureGroups` mutation existed and was reverted; use
`ConfigureWhatsAppGroupsAction` or edit the JSON.

| Key | Default | Notes |
|---|---|---|
| `agent_id` | — | set by `ConnectWhatsAppSessionAction`. The DM/assistant agent, and the group fallback. |
| `session_id` | — | set at connect. |
| `webhook_secret` | — | **required if WaSender sends `x-webhook-signature`** (it does). Empty ⇒ every delivery 401s. |
| `allowed_group_jids` | `[]` | Opt-in. **Empty means no group is ingested at all.** |
| `group_agent_id` | `agent_id` | |
| `group_reply_mode` | `mention` | `never` / `mention` / `always` |
| `direct_conversation_mode` | `lead` | `assistant` = no DM ever opens a Lead |
| `assistant_contact_jids` | `[]` | assistant treatment for these counterparties while others stay leads; matches phone, lid or bare form |
| `direct_reply_mode` | `always` | same values as group |
| `burst_idle_seconds` | `30` | connection-wide, not group-only |
| `burst_mention_idle_seconds` | `8` | |
| `burst_max_seconds` | `180` | |
| `burst_jitter_seconds` | `12` | random seconds **added** to the window so replies aren't a metronome; `0` = deterministic |
| `media_types` | `["whatsapp-image"]` | add `whatsapp-video` to also download the clip itself; the poster frame reaches the agent either way |
| `own_group_lid` | learned | written by the connector; don't hand-set |
| `lead_type`, `receiver_id`, `pipeline_id`, `time_threshold_in_seconds` | — | lead path only |

**Credentials are not here.** `wasender_api_key` / `wasender_base_url` resolve company → app
(`ConfigurationEnum`). Inbound works without them; only the outbound reply fails.

### Reply mode gates speaking, never processing

A group on `mention` still files every message, still bursts, still runs the agent, still fires its
workflow. The mention only decides whether the agent **posts back**. This is what "listens quietly,
publishes the article, answers only when addressed" means.

## Bursts

Group and assistant messages arrive in clumps (text + several photos). The agent runs **once per
burst**, not once per message.

- Parts chain onto a head via `parent_id`; `GroupBurstService::messagesFor()` reads the head plus its
  children.
- Two signals, in precedence: `messageContextInfo.messageAssociation` (an album — deterministic,
  ignores time) then same-speaker-inside-the-idle-window. A different speaker closes the previous
  burst.
- **Chain before downloading media** (`fileIntoBurst()` keeps the order chain → media → arm). The
  first part to reach the head registry wins it, so a message that spends the download unregistered
  loses the head to a part that arrived after it.
- `ProcessGroupBurstJob` is **debounce-superseded**: every part re-arms a cache token and dispatches a
  fresh delayed copy; only the last one still matches the token when it fires.
- The winner **spends the token immediately**, before doing any work. The job has `$tries = 2`, so a
  throw after the agent has answered — the workflow fire is the obvious one — sends it back to the
  queue; clearing the token at the *end* left it valid, the retry re-ran the agent and filed a second
  reply, and since each reply publishes its own post that is two articles from one burst (prod
  736602 / 736603). Deleting only on a token match keeps supersede semantics: an early part still
  finds a mismatch and leaves the burst armed for the winner.
- It runs on the **default queue**. If nothing drains that queue the message files and the agent never
  runs — the single most common cause of "it filed but never answered".

**Replies are not instant.** 8s for an addressed conversation, 30s otherwise. That delay is the
feature; it is what keeps "article now, photo 22 seconds later" as one turn.

### Video

No model takes video — `AttachmentDescriptionService::nativeKind()` returns null for `video/*`, so
the attachment is dropped before the prompt however it is configured. What reaches the agent is the
**poster frame**: WhatsApp ships a `jpegThumbnail` inside the payload, and `attachVideoPoster()`
stores it as an image regardless of `media_types`. No extra fetch, and it doubles as the WordPress
featured image for a video-only post.

Adding `whatsapp-video` to `media_types` additionally downloads the clip and hangs it off the
message — but it does not make the model see more than the poster. Downstream it does matter: the
WordPress publisher leads the post with a player for the clip and keeps the poster as the featured
image, so a video-only story publishes as a video rather than as a still.

## Workflow events — which entity each carries

`fireWorkflow` passes the **entity** as the activity's first argument.

| Event | Fired on | Activity signature must take |
|---|---|---|
| `created` (rule type 1) | the `Message` | `Message` |
| `after-adding-message-to-channel` | the `Channel` | `Channel` (message is in `$params['message']`) |
| `after-adding-message-to-group-channel` | the `Channel` | `Channel` |
| `after-adding-message-to-agent-channel` | the `Channel` | `Channel` |

Both group/agent rule types are seeded by migrations under `database/migrations/Workflow/`
(`composer migrate-workflow`). Without the row, `ProcessWorkflowEventAction` resolves the trigger via
`RuleType::getByName()`, catches `ModelNotFoundException` and returns null — the event is a **silent
no-op** and no rule can ever attach.

The group/agent events exist separately because `AgentChannelResponderActivity` reads
`$params['message']->entity()` as a `Lead` with no guard — a group message hands it a `Channel` and it
fatals. Never point group traffic at the DM event.

### Publishing a burst to WordPress

Attach `PushMessageToWordPressActivity` to the **`created` message rule**, not to the group event.
Two reasons it cannot go on the group event: the entity there is a `Channel` (the activity's
`Message $message` signature would TypeError), and the burst passes `$messages->first()` — the
*inbound* head, which the activity deliberately skips as source material (`from_me === false`).

The agent's reply message is what gets published: `BaseAgentChannelReplyAction::createMessage()` stores
the structured envelope as `response_json`, and creating that message fires `created`. Set
`message_type_id` on the rule (the burst responder's verb is `whatsapp`) or every chat message in the
app becomes a publish candidate.

**The reply message is filed whether or not the agent speaks.** `AgentBurstResponderAction` gates the
**send**, never `createMessage()` — a silent burst still produces the message that carries
`response_json` and fires `created`, so publishing keeps working under `group_reply_mode: mention`.
Gating creation instead would throw every article away. A withheld reply is tagged `not-delivered`, so
channel history distinguishes it from one WhatsApp actually received.

`sendText()` is the single call that reaches WhatsApp, isolated so tests can capture what would go on
the wire — the client is Guzzle-backed, so `Http::fake()` does **not** intercept it.

**A failed agent turn files nothing when the reply is silent.** The kernel normally converts a failure
into prose, which is right for a reader and wrong for a publisher — the apology becomes the article.
The responder passes `fallbackOnFailure: $shouldReply`, so an addressed conversation still gets "I ran
into a hiccup processing that" and a silent group produces no message at all. The burst is lost; the
press release is still in the group and in Kanvas to re-run.

## Staying inside WhatsApp's automation limits

WaSender is an unofficial (Baileys-style) client. Accounts get restricted for "spam, automated or bulk
messaging"; the usual penalty leaves replies working but blocks starting new chats. What the connector
does about it:

- **Bursts collapse N inbound messages into one reply.** This is the single biggest volume reduction —
  seven album webhooks produce one answer, not seven.
- **`mention` mode** lets an agent read and publish while staying silent. Prefer it over `always` on a
  number that also carries human traffic.
- **Reply-only.** Nothing in the group/assistant path initiates a conversation.

- **Jittered reply timing.** `burst_jitter_seconds` adds a random 0–N seconds on top of the window, so
  replies do not land at the same interval every time. Additive on purpose: jittering the window
  itself could shorten it below the point where a burst still collapses into one turn. WaSender
  support's own guidance is a short *variable* delay rather than a longer fixed one.

### Policy: no bulk sending through this provider

The only supported outbound shapes are a **1:1 reply** and a **group reply when the agent is
mentioned**, both delayed by the burst window. `MessageService::sendBulkMessage()` — an unthrottled
`foreach` with no callers — was **deleted** rather than left available. Do not re-add a many-recipient
send here; if broadcast messaging is ever needed it belongs on the official WhatsApp Business Cloud
API, where it is a sanctioned use case.

Still open: there is **no outbound rate limit** at the send chokepoint, so nothing structurally
prevents a caller from looping. One known fan-out exists —
`app/Console/Commands/Intelligence/Messaging/SendDelayMessageCommand` iterates companies → messages →
`SendMessageToLeadAction`, which reaches WhatsApp when the lead's channel is WhatsApp, with no
throttle between sends. Audit that before pointing it at a WaSender number. Outbound-initiated paths
(`SendMessageToLeadAction`, follow-up engagements) should also use the separate
`wasender_base_url_outbound` session so the number that answers is never the number that initiates.

For sustained volume the durable answer is the official WhatsApp Business Cloud API, not tuning this.

## Foot-guns

**Messages are filed under `slug`, not `uuid`.** `CreateMessageAction` writes the deterministic
`wa-{id}-{jid}` value to the `slug` column; `uuid` is a random `UuidTrait` value. Every status handler
once looked messages up by `uuid` and so silently matched nothing — status, receipts, reactions and
deletes never applied, and `message.sent` double-filed instead of updating. Always go through
`ConversationChannelService::findMessageBySlug()`.

**Resolve status-event JIDs through `InboundMessage`, never raw `key.remoteJid`.** Under lid addressing
the raw field holds the lid while the message was filed under the phone form (`remoteJidAlt`), so a
hand-built slug matches nothing.

**Never `lockForUpdate()` on `channels`.** `channels.slug` has only non-unique composite indexes, so
locking a row that does not exist yet gap-locks the range and parallel workers deadlock on the
insert-intention lock (`tests/CLAUDE.md` documents the same trap for `CreateProductAction`).
`getOrCreateChannel` uses a fast unlocked read plus a `Cache::lock` around creation.

**A lid is not a phone number.** Filing `900000000000001` as a cellphone poisons every phone lookup in
the company. A speaker WhatsApp never disclosed a number for gets **no contact row**.

**Mention detection needs our own lid.** `contextInfo.mentionedJid` carries `@lid` values while the
agent only ever stored a phone number. `GroupMentionService` learns the lid from a `fromMe` message or
resolves it via `/api/lid-from-phone-number`, caching it on the receiver as `own_group_lid`; a failed
lookup backs off for an hour. In `mention` mode the agent cannot see a mention until that resolves —
use `always` for a first smoke test rather than concluding detection is broken.

**A provider refusal is not a fault.** `Client` turns any 4xx into `WaSenderRefusedException`, and
`recordMediaFailure()` skips the Sentry report for it — media over WaSender's **25MB decrypt limit**
(a 39MB clip 400s every time, KANVAS-ECOSYSTEM-68N), an expired media key, an unsupported type. None
of it changes on a retry. The message is tagged `media-not-downloaded` with the reason in a
`media_download_error` custom field, and the burst carries on — for a video the poster already
reached the agent, so the article still publishes, just without the clip. Everything else — a missing
api key, a network fault, storage — still reports.

**Our own message never arms a burst.** `messages.upsert` echoes outgoing messages back; bursting one
would have the agent answer itself forever. It is still filed (history must be complete, and it is
where the lid is learned).

## Finding group JIDs

Inbound payloads carry the JID but never a readable name — `extractGroupName()` yields
`WhatsApp Group: 18097...143646` until something supplies the subject. `ListWhatsAppGroupsAction`
(live, `GET /api/groups`) is the only source of names and flags each group `is_allowed`. It takes an
injectable `GroupService` so tests can avoid the live API.

To find groups already sending you traffic, scan `receiver_webhook_calls.raw_payload` for `@g.us`.

## Debugging a silent conversation

1. Receiver run `success` but no reply → check the output shape. `mode: "assistant"` means the
   assistant path; a payload with only `{text,type,uuid,chat_jid,...}` is the **lead** path, which needs
   a responder rule.
2. Reply message exists with `from_ia: true` but nothing arrived on WhatsApp → outbound failed; check
   `wasender_api_key` at company/app.
3. No second message at all after ~15s → the burst job never ran; check the `default` queue worker.
4. Group message skipped with `group not allow-listed` → `allowed_group_jids` is empty or the JID is
   wrong (a bare phone is rejected; it must be `@g.us`).
