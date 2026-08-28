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
| `create_google_sheet_tab(title, sheet_url_or_id)` | Adds a tab to an EXISTING document, without touching any existing tab. **Does not create a spreadsheet**, and unlike the other four it will NOT fall back to the default sheet — see below. |

All five accept either a full Sheets URL or a bare spreadsheet id — the id is extracted with a
regex (`SpreadsheetUrlParser::extractId()`), never asked of the LLM directly. `write_google_sheet`
and `update_google_sheet_cell` also accept live formulas (e.g. `"=SUM(C2:C10)"`) as cell values —
`valueInputOption: USER_ENTERED` interprets them exactly as if typed by hand.

**`sheet_url_or_id` is optional on the four content tools** (read / write / update / clear). Omitting it
falls back to the app's default invoice-tracking sheet (`ConfigurationEnum::DEFAULT_INVOICE_SHEET`, key
`google-sheets-default-invoice-tracker`) via `ResolvesSpreadsheetIdForTool::resolveSpreadsheetId()`.
This is what lets Apex/Arc log every processed invoice to a standing sheet automatically (per
their agent guidance) without the user having to paste a link every time. If neither an explicit
URL nor a default is available, the tool returns `reason: 'no_sheet_configured'`.

**`create_google_sheet_tab` opts out of that fallback** — it passes `allowDefault: false` and returns
`reason: 'sheet_reference_required'` when no sheet is named. The default exists so "log this invoice"
works without pasting a link, which is a safe guess for an append and an unsafe one for a structural
write. The tool's name is also the closest match an LLM has for "create a new Google Sheet" — no tool
does that — so without the opt-out the plausible outcome of that request was a surprise tab on a live
AP document. Its description states the negative fact explicitly for the same reason.

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

### Per-sheet sharing (default path)

The service account can only touch a spreadsheet that has been explicitly shared with it. For
**every** sheet an agent needs to read or write:

1. Open the sheet → **Share**.
2. Add the service account's email (from step 5 above) as **Editor**.

Without this, every call fails with a permission error from the Sheets API — the credentials
being configured correctly is not enough on its own.

### Diagnosing a blocked share

If sharing the sheet with the service account's email fails with something like *"Policy set by
the administrators of [org] prohibits the sharing of items with [service account], because it is
not a Google Account in a compatible allowlisted domain"* — the tenant's Workspace has one of two
sharing restrictions in play. Check **Admin Console → Apps → Google Workspace → Drive and Docs →
Sharing settings**:

- **"Sharing outside of [org]" is set to `Off`, or set to `Allowlisted domains` but the service
  account's domain (`....iam.gserviceaccount.com`) isn't in that list** — a super-admin adds it
  there. This alone sometimes isn't enough if the org has also adopted Trust Rules (next point).
- **The domain shows an "Incompatible with allowlisted domains" warning even after being added**
  — this means the org has migrated Drive sharing enforcement to **Trust Rules**
  (`Admin Console → Rules → Trust rules`), which override the classic domain allowlist entirely.
  The fix is a Trust Rule (scoped to the relevant Org Unit) that allows sharing with anyone in
  allowlisted domains — not just adding the domain to the old list, which no longer has any
  effect once Trust Rules govern that org.
- **"Create rule" for a new Trust Rule is greyed out even for a super-admin** — Trust Rule
  *creation* is gated behind the Workspace edition/add-on, separate from being able to view
  existing rules. If upgrading the plan isn't an option, domain-wide delegation below sidesteps
  the whole sharing-policy question, since it never shares anything externally in the first place.

### Alternative: domain-wide delegation (when direct sharing can't be unblocked)

The fix for any of the above is **domain-wide delegation**: the service account impersonates a
real internal user instead of acting as itself, so no external share is ever needed.

1. In [Google Cloud Console](https://console.cloud.google.com/) → **IAM & Admin → Service
   Accounts** → open the service account → copy its **OAuth2 Client ID** (a numeric id, distinct
   from its email).
2. A Workspace super-admin goes to `admin.google.com` → **Security → Access and data control →
   API Controls → Domain-wide Delegation → Add new** → paste that Client ID → scope
   `https://www.googleapis.com/auth/spreadsheets` → **Authorize**.
3. Set `google-sheets-impersonate-user` (`ConfigurationEnum::IMPERSONATE_USER`) to a real
   Workspace email that already has normal (internal) access to the target sheet(s) — `Client`
   calls `Google\Client::setSubject()` with it, so every API call is made *as that person*, not
   as the service account.
4. The sheet itself is **not** shared with the service account at all under this path — it only
   needs to be a sheet the impersonated user can already open normally.

This key is optional and additive — leave it unset and the connector behaves exactly as before
(the direct-share model above).

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
5. `create_ap_bill` / `create_ar_invoice` (Acumatica) with **`push_to_acumatica: false`**, plus
   `source_email_message_id` and `source_attachment_url`/`source_attachment_filename` (from steps
   2–3) — creates the bill/invoice in Kanvas only (status `pending_approval` for bills, `draft` for
   invoices), giving back the **Kanvas bill/invoice id**. Does **not** push to Acumatica and does
   not call `attach_bill_file`/`attach_invoice_file` (both require an existing Acumatica push) —
   those happen automatically at approval time instead (step 12 below), which is exactly why the
   email message id and the attachment url are stashed as custom fields here.
6. `write_google_sheet` (this connector) — logs the invoice as a new row (no `sheet_url_or_id`
   needed — falls back to the default sheet), automatically, without being asked. **The "ID
   invoice" column holds the Kanvas bill/invoice id from step 5** (not the vendor/customer's own
   invoice number), with status **"Pending"**.
7. `mark_email_as_read` (Gmail) — removes the message's `UNREAD` label, only now that steps 5–6
   succeeded, so the same invoice doesn't get reprocessed on the next `has:attachment is:unread`
   search. Never mark it read before this point — a failed run must still be findable.

**The Acumatica push and the file attachment are out of scope of the automatic intake flow above**
— `create_ap_bill`/`create_ar_invoice` still support pushing in one call (the default,
`push_to_acumatica: true`) for the explicit "create and push this bill/invoice right now" request,
which is a different, still-supported use case from the automatic email flow. In the standard
flow, the push happens later, through the Slack approval phase below.

### Approval phase (Slack → Kanvas → Acumatica → Sheet → email)

Once a bill/invoice is sitting at "Pending", a human approves it over Slack, in natural language —
no chat UI needed. Full detail (the exact tool sequence, the identity-check mechanics, the config
keys and how to obtain each one) lives in **`src/Domains/Scribe/Approvals/CLAUDE.md`** — read that
before touching anything approval-related. The short version: this connector's own part in it is
step 14, `update_google_sheet_cell` three times on the row found via `read_google_sheet` matching
column A (ID invoice) — column **D** (Status) → `"Approved"`, column **E** (Approved Date), column
**F** (Approved By).

### Setup checklist — every key that must exist before this flow works

| # | Key | Where it's set | Connector |
|---|---|---|---|
| 1 | `gmail-client-id` | Settings → Key Configurations | Gmail |
| 2 | `gmail-client-secret` | Settings → Key Configurations | Gmail |
| 3 | `gmail-refresh-token` (scope `gmail.modify` — covers reading AND sending) | Settings → Key Configurations | Gmail |
| 4 | `google-sheets-credentials` | Settings → Key Configurations | GoogleSheets |
| 5 | `google-sheets-default-invoice-tracker` | Settings → Key Configurations | GoogleSheets |
| 6 | Sheet shared as Editor with the service account's `client_email` — **or**, if the tenant's Workspace blocks that, `google-sheets-impersonate-user` set + domain-wide delegation authorized (see below) | Google Sheets UI, per-sheet — or Settings → Key Configurations | GoogleSheets |
| 7 | Sheet has columns A–F as ID invoice / vendor / total / Status / Approved Date / Approved By | Google Sheets UI, per-sheet | GoogleSheets |
| 8–10 | The 3 approval-queue keys (who can approve, their Slack id, the notifier agent's id) | Settings → Key Configurations | see `Scribe/Approvals/CLAUDE.md` |
| 11 | Acumatica credentials (see that connector's own docs) | Settings → Key Configurations | Acumatica |

Steps 1–3 come from the OAuth Playground flow in `Gmail/CLAUDE.md`. Steps 4–7 come from the
service-account flow above plus manually adding the two approval columns to the sheet. Missing any
of 1–11 breaks the flow at that specific step — the tool's error `reason` (`no_sheet_configured`,
`not_authorized`, `no_approver_configured`, an authentication error from `Client::getInstance()`,
etc.) tells you which one.
