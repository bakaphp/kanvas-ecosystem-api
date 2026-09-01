# Intellicheck — ID Verification

Loads when work touches `src/Domains/Connectors/Intellicheck/`. Covers the inbound contract and the folder-threading rule, because both are invisible from the code alone.

## We never call Intellicheck

There is no client and no outbound request in this connector. Intellicheck's bot scans the licence on the customer's device and POSTs the result to us. `IntellicheckHandler` only gates the integration setup on an `intellicheck_id`. So a payload shape question is answered by a captured `receiver_webhook_calls` row, never by reading our code.

## The folder rule — why the report threads as a child

A "folder" in the lead's files UI is **a root message**. [`LeadChannelFilesService::getMessageFileGroups()`](../../Guild/Leads/Services/LeadChannelFilesService.php) groups on `messages.parent_id = 0 OR NULL`, then reads the files off the **newest submitted child** of each root.

So an ID-verification report that creates its own engagement gets its own root message and renders as a **second folder**, next to the one already holding the licence images and the selfie. That was the long-standing bug: `CreateEngagementAction` creates roots, and the report path called it unconditionally.

Two ways the result stays in one folder, both in use:

- **Thread under the scan's engagement.** Pass `parentEngagement` on [`EngagementData`](../../ActionEngine/Engagements/DataTransferObject/Engagement.php) — `CreateEngagementAction` puts it on both the message (`parent_id`) and the engagement (`parent_id`). Reuse the parent's `entity_uuid` as `requestId` too, or `Engagement::stageHistory()` stops grouping the two rows.
- **Reuse an existing engagement.** `EngagementRepository::findEngagementForLeadPeople()` — note the `People` argument. `findEngagementForLead()` filters only by lead + stage, so on a lead with co-buyers it returns whichever row is newest, and a participant's report lands on the main buyer's message.

**Never resolve an engagement with `Engagement::getByMessageId()`** — it has no `fromApp` / `fromCompany`, and the id reaching us comes from an external caller.

## Inbound contract — `IdVerificationReceiverJob`

`POST /v1/receiver/{uuid}?token={leadReceiverUuid}&lead={leadUuid}&eid={engagementId}`

Replaces the legacy Phalcon endpoint `/v2/webhooks/intellicheck`. We generate the bot's callback URL, so the query string kept the legacy shape and only the host and path changed.

Three things that are not guessable:

1. **The body is `base64(JSON)`**, posted raw. Query params still arrive in `$this->webhookRequest->payload` (`ProcessWebhookAttemptAction` uses `$request->input()`, and a raw body leaves the request bag empty while the query string still merges); the bytes are in `raw_payload`.
2. **The payload we score lives under `private_data.result`.** `IdVerificationService` was written against the unwrapped shape because the legacy controller unwrapped it before forwarding. Unwrap before handing anything to it.
3. **`facial.data.photoFace` is the selfie**, base64. Extract it and `unset()` the key before anything persists the payload — it must never end up inside a message's JSON. The receiver forwards it as `images.face`.

`is_showroom` is **derived, never sent**: `! isset($verificationData['ipqs'])`, because an in-store scan carries no IPQS block. Intellicheck owns the body, so we cannot add a field to it.

Auth is the receiver uuid in the path plus the presence of the three query params. There is no shared secret yet; the legacy had none either. Adding one is a deliberate improvement, not a port.

## `generate-id-verification` params — one shape for every caller

```jsonc
{
  "eid": "<engagement id|uuid>",   // optional; web sends it, mobile has no engagement reference
  "people_uuid": "<uuid>",         // optional; omit for the main buyer
  "intellicheck": { "idcheck": {}, "OCR": {}, "ocr_match": {}, "facial": {}, "ipqs": {} },
  "images": { "front": "<base64|uuid>", "back": "<base64|uuid>", "face": "<base64>" }   // all optional
}
```

`intellicheck` must arrive **unwrapped** — `IdVerificationService` reads `idcheck.data` / `ipqs.*` at the top level. The receiver strips `private_data.result` because the bot posts the raw envelope; a GraphQL caller does it itself.

**`eid` is optional and that is deliberate.** With it, the report threads under that engagement's message. Without it, `VerifyPeopleIdAction::resolveEngagement()` falls back to the person's newest submitted `id-verification` engagement, and only creates one if there is none. Mobile depends on that fallback. But an `eid` that is *present and does not resolve* — wrong tenant, another lead — is a caller bug and fails the activity rather than silently falling back.

**`images` maps to message field names, and each side has its own fallback** (`VerifyPeopleIdAction::resolveImageFields()`):

| param | `field_name` | falls back to |
|---|---|---|
| `front` | `drivers_license_front` | `people.driver_license_images['front']` |
| `back` | `drivers_license_back` | `people.driver_license_images['back']` |
| `face` | `face_image` | `intellicheck.facial.data.photoFace` |

**Each side is either a base64 image or the uuid of a row already in `filesystem`.** `VerifyPeopleIdAction::resolveFile()` tells them apart with `Str::isUuid()`: a uuid is looked up tenant-scoped and linked, a non-uuid is uploaded. A uuid that does not resolve skips that side rather than failing the report.

`face` has no caller-side source — **a selfie only ever comes from Intellicheck**, so no caller sends one, and it is always base64.

### Where front and back come from, per caller

| Caller | Source | Mechanism |
|---|---|---|
| `IdVerificationReceiverJob` | the engagement's own message | `resolveImageFields()` reads the parent message's `drivers_license_front` / `_back` and re-links those rows by uuid |
| Mobile | the `images` param | uploads first, sends uuids |

Both are known at call time, which is the point: **`generate-id-verification` never reads
`people.driver_license_images`.** That custom field is a base64 hand-off written by an external caller
and `del()`ed by the old path once attached — a one-shot mailbox whose late arrival is exactly what
`AttachDriverLicenseImagesJob` and the `sleep(20)` were waiting on. A path that cannot wait must not
depend on it.

### `reuseExistingEngagement` — why the folder fix is opt-in

`VerifyPeopleIdAction` is shared, and `VinSolution\Workflow\PushCoBuyerActivity` is the other production
caller — it runs ID verification on a co-buyer inline, and has **no test coverage**. Two of the new
behaviours would silently move where that flow's files land, so both hang off one flag that only
`GenerateIdVerificationActivity` passes:

| `reuseExistingEngagement` | engagement | `driver_license_images` |
|---|---|---|
| `true` (the new verb) | reuse this person's submitted engagement, or thread under `parentEngagement` | never read |
| `false` (default) | always create a root, as before the folder fix | read as a last resort |

They travel together deliberately: a caller that threads into an existing folder is the new path, which
brings its own images and therefore must not depend on a field whose write it would have to wait for.
The custom field is also **read without `del()`ing** — the two verbs coexist, and clearing it would empty
the mailbox `after-id-verification` is still waiting on.

Deleting the deprecated source later is deleting the flag, its `false` branch, and `customFieldImages()`.

**Re-linking the parent's files onto the child is required, not an optimization.** `LeadChannelFilesService::formatMessageFileGroup()` renders the files of the **last submitted child only** — `getLastSubmittedMessage($root) ?? $root` — never the union with the root. So an image left behind on the parent message vanishes from the folder the moment the report threads a child under it. This is exactly what the legacy controller was doing when it copied the images across, and why "the images are already on the engagement" is not enough on its own.

⚠️ **Open: the parent's pivot may not carry the canonical `field_name` yet.**
`CreateEngagementAction::attachFiles()` attaches a form upload as `addFile($file, $file->name)` — the
file's own name, not `drivers_license_front`. Renaming to the canonical fields is
`AttachIdVerificationFilesToMessageActivity`'s job, and that activity has **no PHP caller**: it is wired
by config, hangs off a `Message` event, and therefore has no ordering guarantee against the lead's
`generate-id-verification` rule. If it has not run — or is not configured for the company —
`parentMessageImage()` finds nothing. The message payload (`message.message.data[*].files`) holds the
same files synchronously from creation and is the race-free source; reading it here means sharing that
activity's `collectFiles()` / `resolveFieldName()`.

Every side is guarded by `getFileByName()` before attaching. That matters because `AttachFilesystemAction` does not duplicate a `field_name` — it **repoints the existing pivot row at the new file**. Without the guard, a report landing on an engagement that already holds the customer's own upload would replace it with a re-uploaded copy of the same document, and pay for another upload each run.

## Workflow verbs

| Verb | Producers | Status |
|---|---|---|
| `generate-id-verification` | the frontend and `IdVerificationReceiverJob` | current |
| `after-id-verification` | the legacy Phalcon controller and the current frontend | `@deprecated` |

They **coexist**. The old verb keeps its own rules; nothing was re-pointed. It can only be deleted — case, `rules_types` row, rules — once **both** its producers have migrated; migrating one buys nothing.

⚠️ **Firing a verb runs nothing on its own.** Two DB rows are required and neither is code:

1. A `rules_types` row named after the verb. Without it `ProcessWorkflowEventAction::execute()` catches the `ModelNotFoundException` and returns `null` — verb fired, zero rules, zero error, nothing in Sentry. (`generate-id-verification` ships one via migration.)
2. Per company, a `rules` row (that `rules_types_id` + the Lead system module) plus `rules_actions` → `rules_workflow_actions` pointing at `GenerateIdVerificationActivity`. **Not inherited from the old verb.**

And `executeIntegration` needs an `integrations_companies` row for `INTELLICHECK` on that company, or the activity returns `'No integration configured for this company'` before running.

`ProcessWorkflowEventAction` iterates **every** matching rule (including a global one with `companies_id = 0`) and `DynamicRuleWorkflow` iterates **every** activity on each. Two activities creating an engagement is a second folder that no code change fixes — check the config first.

## Custom-field and file keys that cannot be renamed

Downstream consumers (CRM push, frontend, checklist) read these:

- **Lead / People:** `id_verification`, `get_docs_drivers_license`, `dont-run-id-verification-manual`, `driver_license_images`
- **Company:** `company_manager`, `disable_id_verification_email`, `default_checklist_id`
- **Filesystem:** `id_verify`, `id_expired`, `id_verification_status`, `id_verification_msg`
- **Message:** `engagement_status`, `hashtagVisited`, `text`, `source`
- **`field_name`:** `drivers_license_front`, `drivers_license_back`, `drivers_license_combined`, `face_image`, `id-verification`

`intellicheck_workflow_response` diverges between the legacy (raw report value) and `VerifyPeopleIdAction` (`'passed'` where the status is `'green'`). Confirm with consumers before changing either.

## Report dedup is time-boxed on purpose

`VerifyPeopleIdAction::sendNotification()` guards on a 3-minute cache keyed by **lead id + verified person id**. Not a persisted flag: a queue retry must not send a second report, but a customer re-scanning after a failed check must get one — a permanent flag silently killed the report, the PDF and the engagement for every later verification of that person. And not keyed by display name: a participant whose document is unreadable resolves the name back to the main buyer's, so a name-keyed guard makes the two skip each other.
