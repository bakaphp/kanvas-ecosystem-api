# Scheduled Remote Imports — `Kanvas\Imports`

Loads when work touches `src/Kanvas/Imports/**`. Also read it before changing
`Kanvas\Filesystem\Services\FilesystemRowMapper`, `CsvReaderService`, or anything under
`resources/import-templates/` — they are this domain's shared surface.

A cron pulls files off FTP/SFTP, merges them, and hands the result to the **existing** manual-upload
import pipeline. Nothing after the `FilesystemImports` row is new:

```
RunImportSourcesCommand (every 15 min)
  → ImportSource::isDue()  → queueRun() → RunImportSourceJob (queue: imports)
      → RunImportSourceAction
          download → repair → filter → merge + dedupe by handler   (MergeImportFilesAction)
          → unpublish SKUs no longer in the feed                   (opt-in)
          → FilesystemImports row
              → FilesystemImportObserver → {model}::getImportHandler() → the normal importer
```

`ImportConnection` is the login (app-wide when `companies_id = 0`, else one company's).
`ImportSource` is one company's schedule + file list against that connection.

## All mapping lives in the mapper. Never in PHP.

Column → input mapping belongs in the `FilesystemMapper.mapping` JSON, expressed in the value
language `FilesystemRowMapper` implements (`_const`, `date_col`, `extra.x`, `$concat`, `$coalesce`,
`$map`). A per-company or per-feed difference is an **option on the template**, not a branch in an
Action. If you find yourself adding `if ($company->getId() === 11245)` anywhere in this tree, the
answer is a template option.

`resources/import-templates/*.json` are the shipped starting points. Applying one **copies** its
mapping into a company's own `FilesystemMapper` — the template is never live.

## Editing a template does not update the mappers already made from it

`CreateMapperFromTemplateAction` reuses an existing mapper by matching
`configuration.template.signature` = `key@version?options`. Change the mapping of
`dealer-vehicle-csv.json` without touching `version` and every company that already onboarded keeps
the old mapping forever, silently — the setup command reports "Reused mapper" and looks successful.

**Bump `version` in the JSON whenever you change `mapping`.** The next setup run then creates a new
mapper; existing sources keep pointing at the old one until they are re-pointed, which is the
intended migration story.

## A mapped value must satisfy the target model's PHP types, not just look right

`VariantsWarehouses::$is_new` is typed `bool`. The template originally mapped it to `1`/`0`, which
reads fine in the JSONL and fails every single row with a `TypeError` once the importer hydrates the
model. The `new` *attribute* on the same row is a plain attribute value and stays `1`/`0` — the two
look identical in the mapping and are not.

Tests that stop at the JSONL payload cannot catch this.
[`DealerTemplateEndToEndTest`](../../../tests/Inventory/Integration/Imports/DealerTemplateEndToEndTest.php)
runs the template through the real importer into the database; anything a template maps has to
survive that test.

## Delimiter detection reads the header row first — on purpose

`CsvReaderService::detectDelimiterFromHeader()` runs before League's `Info::getDelimiterStats()`.
League scores a sample of *data* rows, and a dealer feed's `Photo Url List` cell holds pipe-separated
URLs — enough pipes to outvote the real commas, after which every column parses into one and the whole
import maps to null. The header row is the only line guaranteed to be column names.

Don't "simplify" this back to `getDelimiterStats()` alone. It also fixes manual CSV uploads, so the
blast radius of a regression is wider than this domain.

## Nothing is unpublished on a partial feed

`unpublish_missing` unpublishes channel variants whose SKU is absent from today's merged feed. That
is only safe when the feed is complete, so `RunImportSourceAction` skips the run — without
unpublishing anything — when a required file is missing or empty, or when the merge produced zero
rows. A missing file must never read as "every car was sold". Keep that invariant ahead of any
refactor of the download/skip logic.

The same reasoning is why `FtpRemoteFileClient::downloadTo()` checks `stream_copy_to_stream`'s return:
a copy that dies mid-transfer would otherwise report success and merge a truncated feed.

## Adding a second entity type

Everything up to `FilesystemImports` is entity-agnostic — the entity comes from the mapper's
`system_modules.model_name`, and the observer calls `{model}::getImportHandler()`. To import Leads,
People, Orders:

1. Implement `Kanvas\Filesystem\Contracts\EntityImportFilesystemInterface` on that model. Today only
   `Products` does, so a source pointed at anything else builds its row and then fatals in the
   observer.
2. Ship a template JSON whose `system_module` names it.

The inventory-specific parts of `RunImportSourceAction` are already guarded and degrade to nothing:
the dry-run `sample()` returns `[]` for a non-`Products` mapper, and `unpublish_missing` needs both
the flag and a channel. `ImportSource.warehouses_id` / `channels_id` are nullable and only feed
`runExtra()`.

## Credentials

Connection passwords are an `encrypted` cast on `import_connections` and `$hidden` on the model.
They are never read from `.env` or config — there is no `*_FTP_PASSWORD` env var in this flow, and
adding one is not a shortcut worth taking even for a local test.

The host is user-entered (a company admin can create a connection), so `RemoteFileClientFactory` is
the only place a connection may be opened: it enforces the port allow-list, resolves the host through
`Baka\Http\SafeUrl::resolvePublicHost()`, and hands the **resolved IP** to the client so a DNS rebind
after the check can't swap in a private address. FTP additionally sets `ignorePassiveAddress` so a
hostile server can't redirect the data connection.

## Testing

`tests/Ecosystem/Integration/Imports/Fakes/` has a `RemoteFileClient` + factory pair, and
`Concerns/CreatesImportSources` builds a connection/source/mapper set — no network in tests.
`tests/Unit/Imports/` covers the mapper language, schedule maths, merge/dedupe and the SSRF guard.

Suites: `phpunit tests/Unit/Imports tests/Ecosystem/Integration/Imports tests/Inventory/Integration/Imports`.
