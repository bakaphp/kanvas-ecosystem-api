# Gmail Connector

Lets Kanvas agents (Apex, Arc) search a connected Gmail mailbox, read one email's details, and
download an attachment (e.g. an invoice PDF) into Kanvas so it can be processed further.

## Tools

| Tool | Purpose |
|---|---|
| `list_emails(query, max_results?)` | Searches with Gmail's own query syntax (`subject:Invoice has:attachment is:unread`). Returns id/thread_id/subject per match. `max_results` defaults to 10. |
| `read_email_details(message_id)` | Returns From, Date, Subject, body text, and the attachment list (filename + attachment_id) for one message. |
| `download_attachment(message_id, attachment_id, filename)` | Downloads one attachment and stores it as a real Kanvas Filesystem entry — returns `filesystem_id`/`url`, the same way any other uploaded document is referenced. |

## Configuration

Unlike GoogleSheets (a service account), Gmail does **not** work well with service accounts for a
personal/shared mailbox unless you control a Google Workspace domain with delegated authority.
The supported approach here is **OAuth 2.0 with a refresh token** — a one-time manual
authorization that then lets the agent obtain fresh access tokens indefinitely.

Config keys (stored per Kanvas app via the standard custom-fields mechanism):

| Key | `ConfigurationEnum` case |
|---|---|
| `gmail-client-id` | `CLIENT_ID` |
| `gmail-client-secret` | `CLIENT_SECRET` |
| `gmail-refresh-token` | `REFRESH_TOKEN` |

### One-time setup

1. In the same Google Cloud project used for GoogleSheets (or a new one) — **APIs & Services →
   Library** → enable **Gmail API**.
2. **APIs & Services → OAuth consent screen** — configure as External or Internal (Workspace),
   and add the Gmail account you'll authorize as a test user.
3. **APIs & Services → Credentials → Create Credentials → OAuth client ID** — type **Desktop app**
   (simplest for a one-time manual authorization; no redirect URI hosting needed).
4. Download the client id + client secret.
5. Run a one-time authorization to get a refresh token — e.g. with `google/apiclient`'s own OAuth
   flow, or any OAuth Playground-style tool, requesting the `https://www.googleapis.com/auth/gmail.readonly`
   scope. The refresh token from that exchange is what you store — it does not expire on its own
   (it dies only if the user revokes access, the OAuth consent screen is in "Testing" for over 7
   days without publishing, or it goes unused for 6 months).

### Per-app configuration

```graphql
mutation SetGmailCredentials($input: ModuleConfigInput!) {
    setAppSetting(input: $input)
}
```

Call it three times, once per key (`gmail-client-id`, `gmail-client-secret`,
`gmail-refresh-token`) — same mutation already used for Acumatica/GoogleSheets.

**Local/dev shortcut** (`php artisan tinker` inside the app container):

```php
$app = app(\Kanvas\Apps\Models\Apps::class);
$app->set(\Kanvas\Connectors\Gmail\Enums\ConfigurationEnum::CLIENT_ID->value, '...');
$app->set(\Kanvas\Connectors\Gmail\Enums\ConfigurationEnum::CLIENT_SECRET->value, '...');
$app->set(\Kanvas\Connectors\Gmail\Enums\ConfigurationEnum::REFRESH_TOKEN->value, '...');
```

## Security note

A Gmail refresh token is a durable, full-mailbox credential — more sensitive than a
service-account JSON scoped to explicitly-shared sheets, since it grants read access to
*everything* in that inbox for as long as it stays valid. It's stored in plaintext in the
`apps_settings` table, same as every other connector's credentials here — treat it accordingly:
never commit it, never paste it into chat/logs outside the one-time setup, and use a dedicated
mailbox/service account for production rather than a personal inbox.
