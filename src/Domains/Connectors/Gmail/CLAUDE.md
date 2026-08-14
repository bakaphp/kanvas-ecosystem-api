# Gmail Connector

Lets Kanvas agents (Apex, Arc) search a connected Gmail mailbox, read one email's details,
download an attachment (e.g. an invoice PDF) into Kanvas, and extract structured data (vendor,
total, dates) from that PDF so it can be tracked in a sheet and pushed to Acumatica.

## Tools

| Tool | Purpose |
|---|---|
| `list_emails(query, max_results?)` | Searches with Gmail's own query syntax (`subject:Invoice has:attachment is:unread`). Returns id/thread_id/subject per match. `max_results` defaults to 10. |
| `read_email_details(message_id)` | Returns From, Date, Subject, body text, and the attachment list (filename + attachment_id) for one message. |
| `download_attachment(message_id, attachment_id, filename)` | Downloads one attachment and stores it as a real Kanvas Filesystem entry — returns `filesystem_id`/`url`, the same way any other uploaded document is referenced. |
| `extract_invoice_data(filesystem_id, from_email?, subject?)` (in `Tools/Accounting/`) | Reads a downloaded PDF with Kanvas's existing AI PDF classifier and returns vendor/total/currency/dates/line items. The real invoice numbers live in the PDF, never in the email body/subject — always use this before writing an amount anywhere. |
| `mark_email_as_read(message_id)` | Removes the `UNREAD` label so the message stops matching a future `has:attachment is:unread` search. Call only after the invoice is fully logged/pushed — never before, so a failed run can still be found and retried. |

## Configuration

Unlike GoogleSheets (a service account), Gmail does **not** work well with service accounts for a
personal/shared mailbox unless you control a Google Workspace domain with delegated authority.
The supported approach here is **OAuth 2.0 with a refresh token** — a one-time manual
authorization that then lets the agent obtain fresh access tokens indefinitely.

**Scope required: `gmail.modify`, not `gmail.readonly`.** `mark_email_as_read` writes to the
mailbox (removes a label), so the OAuth scope requested during authorization must be
`https://www.googleapis.com/auth/gmail.modify` (a superset of readonly — covers everything this
connector does). **A refresh token is permanently scoped to whatever was consented to when it was
created** — calling `addScope()` in `Client.php` does not retroactively widen an existing token's
permissions. If your refresh token was generated before `mark_email_as_read` existed (i.e. with
only the `gmail.readonly` scope), `mark_email_as_read` will fail with an insufficient-scope error
and you must redo step 4 below with the `gmail.modify` scope checked instead of `gmail.readonly`,
then replace the stored `gmail-refresh-token` with the new one.

Config keys (stored per Kanvas app via the standard custom-fields mechanism):

| Key | `ConfigurationEnum` case |
|---|---|
| `gmail-client-id` | `CLIENT_ID` |
| `gmail-client-secret` | `CLIENT_SECRET` |
| `gmail-refresh-token` | `REFRESH_TOKEN` |

### One-time setup — step by step (tested end to end)

**1. Enable the API**

In the Google Cloud project you want to use (the same one as GoogleSheets, or a dedicated one) —
search **"Gmail API"** at the top of [console.cloud.google.com](https://console.cloud.google.com/)
→ open it → **Enable**.

**2. Google Auth Platform (the current name for "OAuth consent screen")**

- **Branding** — set an app name and support email.
- **Audience** — if the project belongs to a Google Workspace org and the mailbox you're
  connecting is on that same org, leave **User type = Internal** (no test-user allowlisting or
  verification needed, any account in the org can authorize). Only click **Make external** if the
  mailbox is a personal/different-domain Gmail account — that path additionally requires adding
  the mailbox as a **Test user** under Audience.

**3. Create the OAuth client**

**Clients → + Create client**:
- **Application type: Web application** (not Desktop — Desktop-type clients can't register the
  redirect URI the OAuth Playground needs, and authorizing fails with `redirect_uri_mismatch`).
- Name it something identifiable, e.g. "Kanvas Gmail Connector".
- Under **Authorized redirect URIs**, add exactly:
  ```
  https://developers.google.com/oauthplayground
  ```
- Create. Copy the **Client ID** and **Client Secret** shown.

**4. Get the refresh token via OAuth Playground**

1. Go to [developers.google.com/oauthplayground](https://developers.google.com/oauthplayground).
2. Click the ⚙️ gear (top right) → check **"Use your own OAuth credentials"** → paste the Client
   ID and Client Secret from step 3.
3. In the left panel, find **Gmail API v1** and check the
   `https://www.googleapis.com/auth/gmail.modify` scope (not `gmail.readonly` — this connector
   also marks messages as read, which needs write access to the mailbox).
   - **Don't leave text in the API search box after selecting the scope** — leftover search text
     (e.g. from typing "gmail" to find the API) gets sent as a literal extra scope and the
     authorization fails with `Error 400: invalid_scope`. Clear the search box before authorizing.
4. Click **Authorize APIs** → sign in with the mailbox you want the agent to read → accept.
5. Confirm the redirected URL shows *your* `client_id` (not Google's generic Playground client,
   `407408718192.apps.googleusercontent.com`) — that means the "use your own credentials" step
   above didn't actually take effect, and the resulting refresh token would only work inside the
   Playground itself, not with your app.
6. Click **Exchange authorization code for tokens** → copy the `refresh_token` from the response.

The refresh token doesn't expire on its own — it only dies if the user revokes access, the OAuth
consent screen sits in "Testing" (External) for 7+ days without publishing, or it goes unused for
6 months.

### Per-app configuration

In production, use the same **Settings → Key Configurations** admin panel already used for
Acumatica/GoogleSheets — add three separate keys (`gmail-client-id`, `gmail-client-secret`,
`gmail-refresh-token`), one value each. That panel wraps the same `setAppSetting` GraphQL
mutation shown below:

```graphql
mutation SetGmailCredentials($input: ModuleConfigInput!) {
    setAppSetting(input: $input)
}
```

Call it three times, once per key.

**Local/dev shortcut** (`php artisan tinker` inside the app container):

```php
$app = app(\Kanvas\Apps\Models\Apps::class);
$app->set(\Kanvas\Connectors\Gmail\Enums\ConfigurationEnum::CLIENT_ID->value, '...');
$app->set(\Kanvas\Connectors\Gmail\Enums\ConfigurationEnum::CLIENT_SECRET->value, '...');
$app->set(\Kanvas\Connectors\Gmail\Enums\ConfigurationEnum::REFRESH_TOKEN->value, '...');
```

## Security note

A Gmail refresh token is a durable, full-mailbox credential — more sensitive than a
service-account JSON scoped to explicitly-shared sheets, since with the `gmail.modify` scope it
grants both read access to *everything* in that inbox and the ability to change message state
(labels), for as long as it stays valid. It's stored in plaintext in the `apps_settings` table,
same as every other connector's credentials here — treat it accordingly:
never commit it, never paste it into chat/logs outside the one-time setup, and use a dedicated
mailbox/service account for production rather than a personal inbox.
