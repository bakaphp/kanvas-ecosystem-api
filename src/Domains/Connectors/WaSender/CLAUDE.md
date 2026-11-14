# WaSender Connector (WhatsApp)

A Communication provider: `WaSenderHandler` implements `Communication\Contracts\CommunicationProvider`
and is named in `config/communication.php`, with `WhatsAppTransport`, `WaSenderInbound` and
`WaSenderProvisioner` behind it. The Communication domain never imports this connector.

## What the domain owns and what stays here

| The domain | This connector |
|---|---|
| The door: every session posts to the company's **communication receiver** (`ProcessCommunicationWebhookJob`), which has `WaSenderInbound::authenticate()` check the signature before anything is stored | Filing: every event is handled by `ProcessWaSenderEventAction` through `HandlesProviderEvents`, so the shared one-to-one pipeline (`ReceiveMessageAction`) is never given a WaSender message |
| The connection: the company's one WaSender number, owned by an agent (`accounts()`) | The three conversation shapes below, groups, lids, albums, the newsroom flow |
| The answer to a **lead**: `RespondToInboundMessageJob`, as the agent that owns the number, through `Communication::reply()` | The answer to an **assistant chat** or a **group**: the burst handler, inline |
| Outbound to a person: `Communication::for(...)->send(..., channels: ['whatsapp'])` → `WhatsAppTransport`, on the delivery ledger | The reply into a group (a group is not a person, so it is not a ledger delivery) |

Why the shared pipeline is not used: it files a person and a lead from a phone number. A group has
many speakers and no lead, an assistant chat opens no lead, a counterpart can be a lid with no phone
at all, and a lead chat takes its lead type, pipeline, receiver, album chaining and the
`dale`/`process` trigger from the receiver's configuration. Pushing those through
`ReceiveMessageAction` would mean redesigning it; `WaSenderInbound::messages()` returns nothing on
purpose.

**One number per company.** A company's `wasender_api_key` is its session's own key, so a company
has one session and one connection. `WaSenderHandler::connectionFor($app, $company)` finds it.

## Setup and cutover

- **Linking a number** (`ConnectWhatsAppSessionAction`, the `whatsappConnect` mutation): the session
  is created pointing at the communication receiver, its `webhook_secret` is kept on that receiver,
  and `Communication\...\EnsureProviderIntegrationAction` gives the company the integration row Communication sets the
  connection up from (`phone_number`, `agent_id`, `session_id` on the row's `config`). No rule is made.
- **Who owns the number** (`accounts()`): the agent whose `whatsapp_config.phone_number` is the
  number, else the agent that linked the session (`whatsapp_phone_number` custom field,
  `WaSenderHandler::connectedNumber()`), else the `agent_id` on the integration row.
- **A session belongs to a company** when its connection runs on it or one of its agents linked it
  (`WaSenderHandler::ownsSession()`). Sessions are read with the app's account token, so the
  `whatsappConnectionStatus` query answers only for those.
- **A receiver from before** is moved in place by migration
  `2026_11_14_100000_move_wasender_receivers_to_the_communication_webhook`: same row, same URL, same
  secret, same configuration, so nothing changes at WaSender. `kanvas:wasender:adopt-communication
  {app_id}` then gives companies that linked by QR their integration row
  (`WaSenderHandler::selectionFromReceiver()`, which `communication:setup` also reads through
  `legacySelection()`), and `communication:setup` makes the connection. `RetireLegacyWhatsAppInboundAction` retires the old responder rule and hands
  the number to the agent that rule (or the receiver) named.
- **Until the number is a connection** groups and assistant chats keep working (they need none), a
  lead's message is filed, and **no lead is answered**: the responder activity is gone.
- `registerInbound()` only touches a session it has an id for and an account token
  (`wasender_personal_access_token` on the app) to manage; a session wired by hand in WaSender's
  dashboard is left exactly as it is.

## The three shapes

`ProcessWaSenderEventAction::handleMessageUpsert` is a router. In order:

1. `InboundMessage::fromWebhookMessage()` — unroutable payload → silent skip (an expected condition,
   never `report()`).
2. Newsletter (`@newsletter`) → skip. Broadcast-only, nobody to answer.
3. Our own send echoed back (`fromMe`) that matches a ledger delivery (`WaSenderInbound::deliveryOf()`,
   on WaSender's `msgId` or WhatsApp's `key.id`) → skip. The ledger filed it when it left. The same
   check keeps `message.sent` from filing it.
4. `isFirstDelivery()` — 10-minute cache dedupe on the WhatsApp message id. WaSender documents **no**
   delivery or ordering semantics, and with the reply running inline a redelivery is a second answer.
   The key is spent before the message is filed, so it is released when filing throws: the retry is
   the message's only second chance.
5. **Group** (`@g.us`) → `CreateGroupMessageAction` — gated by `allowed_group_jids`.
6. **Assistant 1:1** → `CreateDirectAgentMessageAction`, when `CreateDirectAgentMessageAction::appliesTo()`
   says so.
7. **Everything else** → `CreateLeadMessageAction` — the lead flow.

The group and assistant actions are one filing body (`BaseInboundMessageAction::execute()`); each
only says who the speaker is, what it adds to the stored payload, and its burst windows.

Status-style events (`messages.update`, `messages.reaction`, `message-receipt.update`) arrive as one
object or as a list; `entries()` reads both.

| Shape | Entity | Creates a Lead? | Who replies |
|---|---|---|---|
| Lead DM (default) | `Lead` | yes | the agent that owns the number (`RespondToInboundMessageJob`) |
| Assistant DM | `Channel` | no | the burst job, **inline**, as the number's owner |
| Group | `Channel` | no | the burst job, **inline**, as `group_agent_id`, else the receiver's `agent_id` (`GroupConfigEnum::agentId()`) |

**A lead DM is answered by the number's owner, with no rule.** `CreateLeadMessageAction` stamps the
message with the connection (`message.communication`) and queues `RespondToInboundMessageJob` when an
agent owns it. A number nobody owns, or not yet a connection, files the message and stays silent.
`after-adding-message-to-channel` still fires for a rule a tenant wrote. Assistant and group
conversations reply from the burst flush instead (`FlushMessageBurstJob` → `WaSenderBurstHandler`).

**The lead path has no burst.** Every text is its own turn. The only grouping is the album
heuristic: two images in the same chat within `time_threshold_in_seconds` (default 8) chain under
one parent (`chainConsecutiveImages()`), and only the album head (`parent_id` null) fires the turn —
the photos chained under it ride along as files, and a turn per photo would answer a three-photo
album three times.

**A lead DM with a photo fires the turn too.** The caption is filed as the message's content
(`MessageTypeEnum::extractText()`), which is what the responder reads; the files reach the kernel as
native image/document blocks, and a photo with no caption is prompted as "the customer sent an
attachment". Media is fetched after consent is settled, through the same guarded download the group
and assistant paths use (`Actions\Concerns\DownloadsMessageMedia`): a file WaSender will not hand
over tags the message `media-not-downloaded` and the turn carries on without it. Unlike the burst
paths there is no `media_types` gate here — every image, video, audio and document is fetched.

**`dale` / `process` only fires from a text.** The trigger word resolves the newest image album of
the chat, fires `during-workflow` on it, and hands the agent the "we just processed files" prompt
in place of the trigger word.

## Receiver configuration

Everything lives in the `configuration` (JSON) of the company's **communication receiver** for
WaSender (`WaSenderHandler::receiverFor()`), beside the `communication.provider` key the webhook job
reads — never replace the whole array, merge into it. There is deliberately **no GraphQL
surface** — a `whatsappConfigureGroups` mutation existed and was reverted; use
`ConfigureWhatsAppGroupsAction` or edit the JSON. That action and `ListWhatsAppGroupsAction` find
the receiver through the agent's company (`receiverFor()`), never through an id kept on the agent.

| Key | Default | Notes |
|---|---|---|
| `agent_id` | — | set by `ConnectWhatsAppSessionAction`. The fallback for an assistant chat while the number has no connection owner, and the group fallback. |
| `session_id` | — | set at connect. |
| `webhook_secret` | — | what `x-webhook-signature` is compared with. A receiver that holds one refuses a call without it; one that holds none only takes unsigned calls. |
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

**Credentials are not here.** `wasender_api_key` resolves company → app and `wasender_base_url` is the app's
(`ConfigurationEnum`). Inbound works without them; only the outbound reply fails. A base URL
submitted on the integration form is checked with `SafeUrl` before it is probed
(`Client::validateCredentials()`).

### Reply mode gates speaking, never processing

A group on `mention` still files every message, still bursts, still runs the agent, still fires its
workflow. The mention only decides whether the agent **posts back**. This is what "listens quietly,
publishes the article, answers only when addressed" means.

## Bursts

Group and assistant messages arrive in clumps (text + several photos). The agent runs **once per
burst**, not once per message.

- The debounce is **shared**, not WhatsApp-specific: `Social\Messages\Concerns\ChainsInboundBursts` +
  `MessageBurstService` + `FlushMessageBurstJob`, the same machinery Twilio SMS uses. What stays in
  this connector is `BaseInboundMessageAction::burstPolicy()` (album/speaker correlation keys, the
  mention-shortened close window) and `WaSenderBurstHandler` (what happens once the burst closes).
- Parts chain onto a head via `parent_id`; `MessageBurstService::messagesFor()` reads the head plus its
  children.
- Two signals, in precedence: `messageContextInfo.messageAssociation` (an album — deterministic,
  ignores time) then same-speaker-inside-the-idle-window. A different speaker closes the previous
  burst.
- **Chain before downloading media** (`fileIntoBurstWithMedia()` keeps the order chain → media → arm,
  which is why it calls the trait's two halves itself rather than its `fileIntoBurst()`). The
  first part to reach the head registry wins it, so a message that spends the download unregistered
  loses the head to a part that arrived after it.
- `FlushMessageBurstJob` is **debounce-superseded**: every part re-arms a cache token and dispatches a
  fresh delayed copy; only the last one still matches the token when it fires.
- The winner **spends the token immediately**, before doing any work. The job has `$tries = 2`, so a
  throw after the agent has answered — the workflow fire is the obvious one — sends it back to the
  queue; clearing the token at the *end* left it valid, the retry re-ran the agent and filed a second
  reply, and since each reply publishes its own post that is two articles from one burst (prod
  736602 / 736603). Deleting only on a token match keeps supersede semantics: an early part still
  finds a mismatch and leaves the burst armed for the winner.
- **The receiver reaches the handler as an id, never as a model.** `SerializesModels` only converts a
  top-level QueueableEntity, so a model nested in the flush job's `$params` array is raw-serialized
  and comes back with stale configuration. `WaSenderBurstHandler` re-resolves it from `receiver_id`.
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

The group/agent events exist separately because a rule written for the DM event reads the message's
entity as a `Lead`, and a group message carries a `Channel`. Never point group traffic at the DM event.

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
throttle between sends. Audit that before pointing it at a WaSender number. `WhatsAppTransport` uses
the separate `wasender_base_url_outbound` session for a message that starts a conversation (no chat
with that number exists yet), so the number that answers is never the number that initiates; a
reply, or a message to someone who already wrote in, leaves from the number they know. It also
spaces the parts of one send 30 seconds apart.

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

**Mention detection needs our own lid.** `contextInfo.mentionedJid` carries `@lid` values while all
we know of ourselves is a phone number: the connection's `account_key`, else the number the group
agent linked while the session is not a connection yet. `GroupMentionService` learns the lid from a `fromMe` message or
resolves it via `/api/lid-from-phone-number`, caching it on the receiver as `own_group_lid`; a failed
lookup backs off for an hour. In `mention` mode the agent cannot see a mention until that resolves —
use `always` for a first smoke test rather than concluding detection is broken.

**A provider refusal is not a fault.** `Client` turns any 4xx into `WaSenderRefusedException`, and
`DownloadsMessageMedia::recordMediaFailure()` skips the Sentry report for it — media over WaSender's **25MB decrypt limit**
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
   assistant path; a payload with only `{text,type,uuid,chat_jid,...}` is the **lead** path, which is
   answered only when the number is a connection an agent owns: check `communication:setup`'s report
   and that the filed message carries `communication.connection_id`.
2. Reply message exists with `from_ia: true` but nothing arrived on WhatsApp → outbound failed; a
   lead reply has a failed delivery on the ledger with WaSender's reason, otherwise check
   `wasender_api_key` at company/app.
3. No second message at all after ~15s → the burst job never ran; check the `default` queue worker.
4. Group message skipped with `group not allow-listed` → `allowed_group_jids` is empty or the JID is
   wrong (a bare phone is rejected; it must be `@g.us`).
