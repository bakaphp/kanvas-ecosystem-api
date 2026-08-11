# Google Sheets Connector

Lets Kanvas agents (Apex, Arc) read, append to, and update cells on a Google Sheet the user
shares a link to — e.g. an invoice tracking list a team keeps outside Kanvas.

## Tools

| Tool | Purpose |
|---|---|
| `read_google_sheet(sheet_url_or_id, range?)` | Reads rows/columns. `range` defaults to `A:Z` on the first sheet. |
| `write_google_sheet(sheet_url_or_id, range, values)` | Appends one or more new rows after the last row of data — never overwrites. |
| `update_google_sheet_cell(sheet_url_or_id, range, value)` | Overwrites a specific cell in place, e.g. flipping a status column to "Approved". |

All three accept either a full Sheets URL or a bare spreadsheet id — the id is extracted with a
regex (`SpreadsheetUrlParser::extractId()`), never asked of the LLM directly.

## Configuration

Auth is a Google **service account** — a machine identity, not a per-user OAuth login. The raw
service-account JSON key is stored per Kanvas app via the standard custom-fields mechanism,
under the key `google-sheets-credentials` (`ConfigurationEnum::GOOGLE_SHEETS_CREDENTIALS`).

### One-time setup (per Google Cloud project)

1. Create/select a project in [Google Cloud Console](https://console.cloud.google.com/).
2. **APIs & Services → Library** → enable **Google Sheets API**.
3. **APIs & Services → Credentials → Create Credentials → Service Account**.
4. Open the service account → **Keys → Add Key → Create new key → JSON**. This downloads the
   credentials file.
5. Note the service account's email (e.g. `kanvas-sheets-agent@PROJECT.iam.gserviceaccount.com`).

### Per-app configuration

Store the downloaded JSON on the Kanvas app that will run the tool. There is no admin UI for
this yet — use the existing `setAppSetting` GraphQL mutation (the same one used to configure
Acumatica/Google Calendar), authenticated with that app's admin app key:

```graphql
mutation SetGoogleSheetsCredentials($input: ModuleConfigInput!) {
    setAppSetting(input: $input)
}
```

```json
{
  "input": {
    "key": "google-sheets-credentials",
    "value": "{\"type\":\"service_account\",\"project_id\":\"...\",\"private_key\":\"...\",\"client_email\":\"...\",...}"
  }
}
```

`value` is the entire downloaded JSON file's content as a single string. Run this once per
Kanvas app/tenant — every agent on that app then shares the same configured service account.

**Local/dev shortcut** (no GraphQL round-trip needed): from `php artisan tinker` inside the app
container —

```php
$app = app(\Kanvas\Apps\Models\Apps::class);
$app->set(
    \Kanvas\Connectors\GoogleSheets\Enums\ConfigurationEnum::GOOGLE_SHEETS_CREDENTIALS->value,
    file_get_contents('/path/to/service-account.json'),
);
```

### Per-sheet sharing (always required, cannot be automated)

The service account can only touch a spreadsheet that has been explicitly shared with it. For
**every** sheet an agent needs to read or write:

1. Open the sheet → **Share**.
2. Add the service account's email (from step 5 above) as **Editor**.

Without this, every call fails with a permission error from the Sheets API — the credentials
being configured correctly is not enough on its own.

## Security note

The credentials are stored in plaintext in the `apps_settings` table, same as every other
connector's credentials in this codebase (Acumatica, Google Calendar, etc.) — this is an
existing, accepted pattern here, not something specific to this connector. Treat the JSON key
file itself with the same care as any other production secret: don't commit it, don't paste it
into chat/logs outside the one-time setup, and use a dedicated production service account (never
reuse a personal/dev one).
