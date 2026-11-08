# Google Sheets Connector

Lets Kanvas agents read, append to, and update cells on a Google Sheet the user shares a link to.
Catalog tools granted per agent; not hardcoded on any agent (the AP/AR agents dropped them on 2026-11-07).

## Tools

| Tool | Purpose |
|---|---|
| `read_google_sheet(sheet_url_or_id?, range?)` | Reads rows/columns. `range` defaults to `A:Z` on the first sheet. |
| `write_google_sheet(range, values, sheet_url_or_id?)` | Appends one or more new rows after the last row of data — never overwrites. `values` is a JSON-encoded string (e.g. `'[["1498","Vendor",250.00,"Pending"]]'`), not a native array — see note below. |
| `update_google_sheet_cell(range, value, sheet_url_or_id?)` | Overwrites a specific cell in place, e.g. flipping a status column to "Approved". |
| `clear_google_sheet_range(range, sheet_url_or_id?)` | Wipes the values in a cell/row/range without deleting the row itself — the safe alternative to a structural delete. |
| `create_google_sheet_tab(title, sheet_url_or_id)` | Adds a tab to an EXISTING document, without touching any existing tab. **Does not create a spreadsheet** (`create_google_spreadsheet` does), and unlike the other four it will NOT fall back to the default sheet — see below. |
| `create_google_spreadsheet(title)` | Creates a NEW, empty spreadsheet and returns its id and URL. **Only on the agent-sign-in path** — it refuses with `no_google_account_connected` under the service account, whose documents are owned by an account no person can open. |

All five accept either a full Sheets URL or a bare spreadsheet id — the id is extracted with a
regex (`SpreadsheetUrlParser::extractId()`), never asked of the LLM directly. `write_google_sheet`
and `update_google_sheet_cell` also accept live formulas (e.g. `"=SUM(C2:C10)"`) as cell values —
`valueInputOption: USER_ENTERED` interprets them exactly as if typed by hand.

**`sheet_url_or_id` is always needed.** There used to be an app-level default invoice-tracking sheet
(`google-sheets-default-invoice-tracker`) that the AP/AR agents logged every invoice to; it is gone —
Kanvas is the tracker and the Sheets tools are no longer on the AP/AR agents (decision 21, 2026-11-07).
An omitted reference returns `reason: 'sheet_reference_required'`, never a guessed document. The
parameter stays optional in the schema on purpose: the refusal has to reach `__invoke()` so it can point
at `create_google_spreadsheet`, the closest-named tool to "create a new Google Sheet" being
`create_google_sheet_tab`, whose description states the negative fact explicitly.

**Why `values` is a JSON string, not `PropertyType::ARRAY`:** NeuronAI's `ToolProperty::getJsonSchema()`
never emits an `items` sub-schema for array-type parameters. Gemini's function-calling schema
validation requires `items` on any `array`-typed parameter and rejects the whole request with a
`400 GenerateContentRequest.tools[...].parameters...` error otherwise — and since Gemini validates
every tool in the array together, one bad array-typed property breaks every tool call on that
agent, not just this one. `write_google_sheet` sidesteps it by declaring `values` as `STRING` and
`json_decode()`-ing it in `__invoke()`. Do the same for any future tool parameter that would
naturally be an array/object, until NeuronAI adds `items`/`properties` sub-schema support.

## Configuration

There are **two** Google identities a tool can act with, and they are not interchangeable:

| | Agent sign-in (preferred) | Service account |
|---|---|---|
| Who it acts as | the Google account **that agent** signed in with | one machine identity for the whole app |
| Reaches | whatever that person can open | only sheets explicitly shared with it |
| Set up by | Connect on the agent's Google Sheets MCP server, once per agent | an admin, once per app |
| Sharing step | none | every sheet shared as Editor (or domain-wide delegation) |

`Client::forAgent()` resolves the first from the agent's MCP credential (renewing an expired token)
and returns null when the agent has not connected — the tools then pass null to the action, which
falls back to `Client::getInstance()` and the service account below, so an agent with its own
connection needs no sharing at all.

`GoogleSheetsReadinessService` reports ready on **either** identity. It is asked per app, never per
agent, so the sign-in half answers only "an agent could connect here"; an agent that has not connected
is refused by the tool, naming the fix. Without that, `ToolGrantResolver` would refuse the sheets tools
and the agent would be told Google Sheets is not set up while its own connection would have worked.

The rest of this section is the **service-account** path. The raw service-account JSON key is stored
per Kanvas app via the standard custom-fields mechanism, under the key `google-sheets-credentials`
(`ConfigurationEnum::GOOGLE_SHEETS_CREDENTIALS`).

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

## Not part of the AP/AR invoice flow

The AP/AR agents used to log every processed invoice to a tracking sheet and flip its row on approval.
That flow is gone (decision 21, 2026-11-07): Kanvas holds the status, approver, dates and PDF, and the
intake/approval flow is documented in `src/Domains/Scribe/Approvals/CLAUDE.md`.
