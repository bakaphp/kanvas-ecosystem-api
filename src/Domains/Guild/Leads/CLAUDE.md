# Leads — Kanvas Ecosystem API

Loads when work touches `src/Domains/Guild/Leads/`. Covers the receiver → lead → email flow, because "did the receiver send an email?" is deceptively hard to answer from the code.

## Receiver email flow — where the template REALLY comes from

When a lead arrives through a receiver ([`CreateLeadsFromReceiverJob`](Jobs/CreateLeadsFromReceiverJob.php)), whether an email is sent — and which template renders — is decided by **the rotation record's `config`, not the job**. This trips people up constantly. The chain:

1. **Job reads its own default** — `email_template` from `receiver->configuration`, almost always `null`:
   ```php
   $emailTemplate = $this->receiver->configuration['email_template'] ?? null;   // usually null
   $userFlag      = $this->receiver->configuration['flag'] ?? 'user';
   ```
2. **Job passes that null down** to [`SendRotationEmailsAction::execute($payload, $userFlag, $emailTemplate)`](Actions/SendRotationEmailsAction.php).
3. **The rotation config WINS** — the job's null is only a fallback:
   ```php
   // rotation config first; the job's null is only used if the rotation has none
   $template = $this->leadRotation?->config['email_template'] ?? $defaultEmailTemplate;
   if ($template === null) {
       return;   // ← no template anywhere → NOTHING is sent
   }
   ```
   So passing `emailTemplate = null` from the job does **not** mean "no email" — if the receiver points at a rotation whose `config.email_template` is set, that value is used and mail goes out.

**Gotcha: the `sent_email.template` field in the job response is the JOB's value (the null), not the rotation's.** A response showing `"template": null` does NOT prove no email was sent — the rotation may have supplied one. To know for sure, inspect `leadReceiver->rotation->config['email_template']`.

### `user-` / `lead-` template name prefixing (the "concatenation")

[`SendLeadEmailsAction`](Actions/SendLeadEmailsAction.php) does NOT send the base template name as-is. It **prefixes** it per recipient:
```php
$userTemplate = 'user-' . $this->emailTemplate;   // e.g. 'user-lead-company-email'  → agents / rotation users / extraEmails
$leadTemplate = 'lead-' . $this->emailTemplate;   // e.g. 'lead-lead-company-email'  → the lead's own contact email
```
So a base template `lead-company-email` requires **two** registered mail templates: `user-lead-company-email` and `lead-lead-company-email`. Register whichever recipients your notification mode targets, or the send silently `report()`s an exception (`safeSend` swallows it).

### Who receives it — two independent rotation-config knobs

Both read off `rotation->config` (see [`SendRotationEmailsAction`](Actions/SendRotationEmailsAction.php) and the two enums in `Enums/`):

- **`notification_mode`** (`LeadNotificationModeEnum`, default `NOTIFY_ALL`) — recipient *axis*:
  - `NOTIFY_ALL` → agents **and** the lead's contact email
  - `NOTIFY_AGENTS` → agents only (lead gets nothing)
  - `NOTIFY_LEAD` → lead's contact only (agents get nothing)
- **`notification_user_mode`** (`LeadNotificationUserModeEnum`, default `NOTIFY_OWNER`) — *which* users on the agent side:
  - `NOTIFY_OWNER` → only the receiver's owner (`leadReceiver->user`)
  - `NOTIFY_ROTATION_USERS` → owner + every active rotation agent, AND `rotation.leads_rotations_email` CCs are injected as `extraEmails`. Falls back to owner-only if the rotation has zero active agents.

The `flag` (`'user'` default) picks the "owner" identity: `flag === 'user'` → `leadReceiver->user`; otherwise the resolved lead owner (`$this->user`). It is **not** concatenated into the template name — that's the `user-`/`lead-` prefixing above, which is separate.

### Per-receiver address: `leads_receivers.notification_email`

A rotation is a *distribution policy* (who works the lead); a receiver is a *source* (which form it came from). When several forms share one agent pool but each must notify a different address, the address goes on the receiver — cloning the rotation per form would split the round-robin `hits` counters to achieve something unrelated to distribution.

`notification_email` is a nullable comma-separated string mirroring `leads_rotations_email`, resolved in `SendRotationEmailsAction::resolveExtraEmails()`. It **overrides** the rotation's address and fires **unconditionally** — unlike the rotation's, it is not gated by `NOTIFY_ROTATION_USERS` + a non-empty agent pool, because a source's address should always receive its own leads. Recipients still get the same `user-<template>` mail, so only the address differs.

**The receiver must be wired to a rotation for this to fire at all.** `CreateLeadsFromReceiverJob::sendEmails()` returns `['no email sent']` on a null owner *before* it constructs `SendRotationEmailsAction`, and with `rotations_id = 0` `resolveOwner()` only produces an owner when the payload carries a `Member` key that matches an Agent. A plain contact form posting to a rotation-less receiver therefore sends nothing — no rotation email, no receiver email — regardless of `notification_email` or a webhook `email_template`. The template itself also still comes only from the rotation (or the webhook fallback).

For attribution you need no config at all — leads already carry `leads_receivers_id` and a copied `source_name`.

## When defaults are created — company onboarding vs. the SalesAssist command

Two very different creation paths; don't conflate them:

- **Company creation / onboarding** (`OnBoardingJob` → [`Guild\Support\Setup::run()`](../Support/Setup.php)) creates a single **`Default Receiver` with `rotations_id: 0` and no `config`.** No rotation → template resolves to `null` → **a fresh company's default receiver sends no receiver emails.** There is no automatic `email_template` default on company creation.
- **The `lead-company-email` default is opt-in, per app/company**, applied only by the manual/deploy command `kanvas:sa-setup-receivers` ([`SetupReceiversCommand`](../../../../app/Console/Commands/Connectors/SalesAssist/SetupReceiversCommand.php)). It creates/updates a **`LeadRotation`** with `config = { email_template: 'lead-company-email', notification_mode: NOTIFY_AGENTS, notification_user_mode: NOTIFY_ROTATION_USERS }` and wires the SalesAssist receivers to that rotation. The base template name lives in [`EmailTemplatesEnum::LEAD_COMPANY_EMAIL`](../../Connectors/SalesAssist/Enums/EmailTemplatesEnum.php).

So: **template config lives on the rotation, is set by a command, and is not part of company onboarding.** If a receiver "isn't emailing", check that its rotation exists and its `config.email_template` is populated — not the job or the receiver row.
