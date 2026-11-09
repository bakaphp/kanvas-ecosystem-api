# Microsoft Connector (Outlook + OneDrive)

Native Graph integration: Outlook mail and OneDrive documents. OneDrive is exposed to agents through
the 8 tools in `src/Domains/Intelligence/Agents/Neuron/Tools/OneDrive/`.

## App Registration

Must allow **personal and organizational** accounts, with redirect `{app.url}/v1/oauth/callback`,
delegated permissions `Files.ReadWrite` and `User.Read` (plus the mail scopes) and a client secret. For personal
accounts `microsoft_tenant_id` must be `common` (or `consumers`); a specific tenant id rejects them.

## Scopes

- `MicrosoftScopes::forAuthorization()` always appends `Files.ReadWrite` and `User.Read` to the app's configured scopes. `User.Read` is for the callback's `GET /me`; without it a personal account connects (tokens stored) and then the callback answers 403 `Authorization_RequestDenied`.
- The scopes Microsoft actually granted are stored per company and app as `microsoft_granted_scopes-{appId}-{companyId}`, and refresh uses
  those (`MicrosoftScopes::forRefresh()`), not the default. Microsoft rejects a refresh that asks for
  scopes the user never consented to.
- Companies connected before OneDrive existed have nothing stored, so refresh falls back to the mail
  scopes and Outlook keeps working. They must **reconnect** to get OneDrive.

## Writing documents

- **Word** is edited by DOM on `word/document.xml` only, never a PhpWord round-trip (that loses
  formatting). `w:tab` / `w:br` / `w:cr` read back as `\t` / `\n` and a replacement cannot span them
  (`text_not_found`). XML-invalid characters are removed with `Baka\Support\Str::stripInvalidXmlChars()`.
- **Excel** goes through PhpSpreadsheet, which silently drops every package part it does not model.
  So writes use an **allow-list** (`ExcelDocument::EDITABLE_PARTS`, checked against PhpSpreadsheet
  5.x's Xlsx reader and writer): any zip entry outside it (pivot tables, threaded comments and
  `xl/persons/`, `xl/connections.xml`, slicers, external links, `vbaProject.bin`, `customXml/`, ...)
  refuses the edit with `unsupported_type` naming the first offending part. `xl/calcChain.xml` is
  allowed although it is not written back: it is a calculation cache Excel rebuilds. Before adding
  an entry, confirm PhpSpreadsheet both reads and writes it.
- Reads stop at 500 rows and 50 columns (`truncated: true` when either cut data). With no sheet
  named, read and write use the **first** sheet, never the workbook's active one. Dates read back as
  serial numbers.
- Every bytes ↔ path round trip (ZipArchive and the PhpOffice readers/writers need a path) goes
  through `OneDrive/Documents/Support/TemporaryFile`, the one file allow-listed in
  `NoUnguardedUrlFetchTest` for it. Do not add another `file_get_contents()` to the documents.

## Concurrency and deletion

- Replacing a file always sends `If-Match` with the eTag. No eTag: refuse (the guard is `OneDrive/Actions/Concerns/EditsOneDriveDocument::replaceEdited()`). HTTP 412 becomes `file_changed`. HTTP 423 (open in Word/Excel Online or desktop) becomes `file_locked`.
- An account with no drive becomes `onedrive_not_available`. Graph says so only in the message text (`Tenant does not have a SPO license.` with 400 for a work tenant without SharePoint, `User's mysite not found.` with 404 for an unprovisioned work account), so the client matches the text before the status.
- Create uses `conflictBehavior=fail`, so it never overwrites.
- Delete goes to the recycle bin. The drive root can never be deleted; `OneDriveClient::isRootPath()`
  is the single rule for the input, do not re-derive it. Because an id (`root`, or the root's real id)
  can still name the root, `delete()` first GETs the item and refuses one with a `root` facet, then
  deletes by `items/{id}`, so every delete is a GET plus a DELETE.
- A path segment of `.` or `..` (folder path or new file name) is refused before any request is sent:
  `/Docs/..` would otherwise address the parent, the root included.
- An upload session that answers without an `uploadUrl` fails as `request_failed`; the chunks are
  never sent to an empty URL.

## Confining a company to one folder

Company setting `microsoft_onedrive_root_folder-{appId}-{companyId}` (`ConfigurationEnum::ONEDRIVE_ROOT_FOLDER`),
e.g. `/Clientes/ACME`. Unset, empty or `/` means the whole drive.

```php
$company->set('microsoft_onedrive_root_folder-' . $app->getId() . '-' . $company->getId(), '/Clientes/ACME');
```

- It is enforced in `OneDriveClient`, which every tool goes through, so it holds for all eight tools, not
  only search. To the model the folder **is** `/`: paths are resolved under it and every path it gets back
  is relative to it.
- An id can point anywhere in the drive, so under a configured folder an id is fetched first and refused
  with `outside_allowed_folder` unless its path is inside. An item Graph reports without a path (the drive
  root) counts as outside. A write by id therefore costs one extra GET.
- The folder itself cannot be deleted (`/` or its id). Dot segments are refused, so a path cannot climb out.
- Each tool result carries a `scope` note while the folder is set. Without it the model told the user a
  file outside the folder did not exist.
- The folder must exist; if it does not, every call answers `not_found`.

## Search

- **Graph ignores the folder of a folder-scoped search on personal accounts** (`/me/drive/root:/Documents:/search(q=...)`
  returns `/Desktop` and `/Pictures` matches, checked live 2026-11-05). So `onedrive_search(folder:)` searches the
  whole drive and keeps the matches under that folder itself: pages of 200, at most 5 pages, and only a
  `@odata.nextLink` under the Graph base URL is followed.
- Read `@odata.nextLink` as a literal key (`$response->json()['@odata.nextLink']`). `$response->json('@odata.nextLink')`
  treats the dot as a path and always returns null.
- OneDrive's search index lags: a file created or edited minutes earlier is not found yet, while listing the
  folder sees it at once. The tool description tells the model to list when it knows where a file is.

## Agent tools

- `CallsOneDrive` has its own strict JSON decoders on purpose. `Tools/Traits/DecodesJsonObjectParam`
  returns `[]` on bad JSON and cannot produce `invalid_input`.
- `invalid_input` means the model's arguments were wrong, so only `Tools/OneDrive/Exceptions/InvalidToolInputException`
  maps to it. Any other `InvalidArgumentException` (HTTP client, PhpSpreadsheet) is a `request_failed`
  and is reported. Throw `InvalidToolInputException` only for a bad tool argument.
- Grant an agent either the native tools or the `microsoft365_mcp` MCP row, not both. Not enforced in code.
