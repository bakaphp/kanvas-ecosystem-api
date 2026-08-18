# Google Sheets Connector

Lets Kanvas agents (Apex, Arc) read, append to, and update cells on a Google Sheet the user
shares a link to — e.g. an invoice tracking list a team keeps outside Kanvas.

## Tools

| Tool | Purpose |
|---|---|
| `read_google_sheet(sheet_url_or_id?, range?)` | Reads rows/columns. `range` defaults to `A:Z` on the first sheet. |
| `write_google_sheet(range, values, sheet_url_or_id?)` | Appends one or more new rows after the last row of data — never overwrites. `values` is a JSON-encoded string (e.g. `'[["1498","Vendor",250.00,"Pending"]]'`), not a native array — see note below. |
| `update_google_sheet_cell(range, value, sheet_url_or_id?)` | Overwrites a specific cell in place, e.g. flipping a status column to "Approved". |
| `clear_google_sheet_range(range, sheet_url_or_id?)` | Wipes the values in a cell/row/range without deleting the row itself — the safe alternative to a structural delete. |
| `create_google_sheet_tab(title, sheet_url_or_id?)` | Adds a brand-new sheet/tab to the document, without touching any existing tab. |

All five accept either a full Sheets URL or a bare spreadsheet id — the id is extracted with a
regex (`SpreadsheetUrlParser::extractId()`), never asked of the LLM directly. `write_google_sheet`
and `update_google_sheet_cell` also accept live formulas (e.g. `"=SUM(C2:C10)"`) as cell values —
`valueInputOption: USER_ENTERED` interprets them exactly as if typed by hand.

**`sheet_url_or_id` is optional on all five tools.** Omitting it falls back to the app's default
invoice-tracking sheet (`ConfigurationEnum::DEFAULT_INVOICE_SHEET`, key
`google-sheets-default-invoice-tracker`) via `ResolvesSpreadsheetIdForTool::resolveSpreadsheetId()`.
This is what lets Apex/Arc log every processed invoice to a standing sheet automatically (per
their agent guidance) without the user having to paste a link every time. If neither an explicit
URL nor a default is available, the tool returns `reason: 'no_sheet_configured'`.

**Why `values` is a JSON string, not `PropertyType::ARRAY`:** NeuronAI's `ToolProperty::getJsonSchema()`
never emits an `items` sub-schema for array-type parameters. Gemini's function-calling schema
validation requires `items` on any `array`-typed parameter and rejects the whole request with a
`400 GenerateContentRequest.tools[...].parameters...` error otherwise — and since Gemini validates
every tool in the array together, one bad array-typed property breaks every tool call on that
agent, not just this one. `write_google_sheet` sidesteps it by declaring `values` as `STRING` and
`json_decode()`-ing it in `__invoke()`. Do the same for any future tool parameter that would
naturally be an array/object, until NeuronAI adds `items`/`properties` sub-schema support.

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

Store the downloaded JSON on the Kanvas app that will run the tool. In production, use the same
**Settings → Key Configurations** admin panel already used for Acumatica — add a new key named
`google-sheets-credentials` with the entire downloaded JSON file's content as its value. That
panel wraps the same `setAppSetting` GraphQL mutation shown below, which can also be called
directly (authenticated with that app's admin app key):

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

### Default invoice-tracking sheet (optional)

To have Apex/Arc log every processed invoice automatically without being asked, configure
`google-sheets-default-invoice-tracker` the same way — same Settings panel, or via tinker:

```php
$app->set(
    \Kanvas\Connectors\GoogleSheets\Enums\ConfigurationEnum::DEFAULT_INVOICE_SHEET->value,
    'https://docs.google.com/spreadsheets/d/.../edit',
);
```

The sheet still needs the service account shared as Editor on it (see below) — the default just
saves the agent from needing the URL repeated in every message. Without this key set, the agent
falls back to asking for a sheet link when it needs to log something.

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

## Full invoice-processing flow (Gmail → Kanvas → Sheet)

This connector is one leg of a pipeline (see also `src/Domains/Connectors/Gmail/CLAUDE.md` and the
Acumatica connector). End to end, when Apex/Arc process an emailed invoice, the agent:

1. `list_emails` (Gmail) — finds unread invoice emails.
2. `read_email_details` (Gmail) — reads the body + attachment list.
3. `download_attachment` (Gmail) — saves the PDF to Kanvas, returns `filesystem_id`/`url`.
4. `extract_invoice_data` (Accounting) — reads the PDF with AI, gets the real vendor/total/dates.
   The email body/subject is never the source of truth for these — always read the PDF.
5. `create_ap_bill` / `create_ar_invoice` (Acumatica) with **`push_to_acumatica: false`** — creates
   the bill/invoice in Kanvas only (status `pending_approval` for bills, `draft` for invoices),
   giving back the **Kanvas bill/invoice id**. Does **not** push to Acumatica and does not call
   `attach_bill_file`/`attach_invoice_file` (both require an existing Acumatica push) — those
   happen later, as a separate manual step once a human approves the bill/invoice in Kanvas.
6. `write_google_sheet` (this connector) — logs the invoice as a new row (no `sheet_url_or_id`
   needed — falls back to the default sheet), automatically, without being asked. **The "ID
   invoice" column holds the Kanvas bill/invoice id from step 5** (not the vendor/customer's own
   invoice number), with status **"Pending"**.
7. `mark_email_as_read` (Gmail) — removes the message's `UNREAD` label, only now that steps 5–6
   succeeded, so the same invoice doesn't get reprocessed on the next `has:attachment is:unread`
   search. Never mark it read before this point — a failed run must still be findable.

**The Acumatica push, the sheet's flip to "Approved", and the file attachment are explicitly out of
scope of this automatic flow** — they happen later, as a separate (currently manual) step once a
human reviews and approves the bill/invoice sitting in Kanvas. That later step is not built yet as
of this writing; `create_ap_bill`/`create_ar_invoice` still support pushing in one call (the
default, `push_to_acumatica: true`) for the explicit "create and push this bill/invoice right now"
request, which is a different, still-supported use case from the automatic email flow above.

The agent's final reply must always give the complete breakdown of what happened so far (Kanvas
id, vendor, invoice number, amount, GL account, subaccount, memo, status) — the user looks this up
in Kanvas afterward and needs every field, not a short summary. There is no Acumatica reference at
this stage — say so plainly instead of omitting it.

### Setup checklist — every key that must exist before this flow works

| # | Key | Where it's set | Connector |
|---|---|---|---|
| 1 | `gmail-client-id` | Settings → Key Configurations | Gmail |
| 2 | `gmail-client-secret` | Settings → Key Configurations | Gmail |
| 3 | `gmail-refresh-token` | Settings → Key Configurations | Gmail |
| 4 | `google-sheets-credentials` | Settings → Key Configurations | GoogleSheets |
| 5 | `google-sheets-default-invoice-tracker` | Settings → Key Configurations | GoogleSheets |
| 6 | Sheet shared as Editor with the service account's `client_email` | Google Sheets UI, per-sheet | GoogleSheets |

Steps 1–3 come from the OAuth Playground flow in `Gmail/CLAUDE.md`. Steps 4–6 come from the
service-account flow above. Missing any of 1–6 breaks the flow at that specific step — the tool's
error `reason` (`no_sheet_configured`, an authentication error from `Client::getInstance()`, etc.)
tells you which one. Acumatica credentials are no longer required for this automatic flow (nothing
is pushed to Acumatica here) — they're still needed for the separate `push_to_acumatica: true`
use case and the future manual-approval step.
