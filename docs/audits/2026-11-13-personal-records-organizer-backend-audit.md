# Backend readiness audit — bounded personal records organizer

**Date:** 2026-11-13
**Repository:** `kanvas-ecosystem-api`
**Branch base:** `origin/development` (`5302665263`, `Merge pull request #12102 from bakaphp/feat/voice-default-voice`)
**Scope:** read-only audit. No schema, code, migration or configuration change. No vendor contact, no spend, no real health data.

## 0. What this audit was asked to verify

Six capabilities, in the order the ticket named them:

1. Authenticated ownership
2. Document metadata and storage
3. Text extraction with provenance
4. Correction
5. Deletion and export lifecycle
6. Consent and retention

## 1. Method and limits

**Method.** Code-level reading of the repository at `origin/development`: migrations, domain models, actions/services, GraphQL schema files, and resolvers. Every claim below cites `file:line`. Nothing was inferred from a vendor's documentation, a roadmap, a comment promising future work, or an endpoint name that merely *sounds* right.

Two rules were applied when deciding "exists":

- **"Exists" means the need is served, not that a similarly named field is present.** A `filesystem` table with a `users_id` column is not per-user privacy if no query filters on it.
- **Kanvas is multi-tenant, so generic mechanisms count.** A value stored in `custom_fields`, an entity's JSON config or `filesystem_settings` is genuinely supported and does not need a new column. A need those cover is a gap in the *product surface*, not in the storage layer.

**What could not be verified.**

- **The attached plan was not available.** The task described a plan as the scope, but no plan file exists in the worktree or in the factory context for this session. The six areas named in the ticket were used as the scope instead. If the plan defines a narrower or different set of behaviours, this report may be answering the wrong six questions.
- **No local execution.** PHP, Composer, and the project's Docker stack are not installed on this machine. No test, migration, PHPStan run, or php-cs-fixer pass was performed. Nothing here is a runtime observation — every statement is a reading of the source.
- **Production configuration was not inspected.** Object-storage bucket policy, CDN caching rules, database-at-rest encryption, and which apps have enabled the knowledge/RAG switch are deployment facts, not repository facts. Section 7 lists them as unknowns.
- **No data was read.** No database was queried.

## 2. Verdict in one screen

| # | Requirement | Status | One-line reason |
|---|---|---|---|
| 1 | Authenticated ownership | **Gap** | Auth exists and files carry an owning `users_id`, but the only listing path is by entity attachment, and any same-company user can read another user's attachments. |
| 2 | Document metadata and storage | **Partial** | Storage, metadata rows, upload/attach/detach/edit/delete all exist; no per-record privacy, no extension allow-list, no checksum at the generic layer, no scan, public visibility. |
| 3 | Text extraction with provenance | **Partial** | Extraction is implemented and chunk provenance is recorded, but there is no API that returns extracted text for a file, and indexing is per-app opt-in. |
| 4 | Correction | **Gap** | Metadata (name, field, weight) can be edited; extracted text and its derived index cannot be corrected, and there is no revision history to correct against. |
| 5 | Deletion and export lifecycle | **Gap** | Delete works for a file and for knowledge documents; account deletion is a stored request that is never processed by the scheduled command; there is no export of a person's records. |
| 6 | Consent and retention | **Gap** | Consent is marketing opt-out only; retention exists for agent memory, ledger and fiscal documents, but not for personal records or files. |

Nothing in the repository implements a personal *record* — there is no `Document`, `Record`, `Vault`, or similar entity. What exists is a generic file-attachment layer used by dozens of business entities: 71 `files: [Filesystem...]` fields across 36 schema files under `graphql/schemas/`. **A personal records organizer cannot be built on today's API without new backend work.**

## 3. Capability detail

### 3.1 Authenticated ownership — **Gap**

**What is verified.**

- Every relevant surface is behind authentication. `entityFiles` and every filesystem mutation sit under `extend type Query @guard` / `extend type Mutation @guard` in `graphql/schemas/Ecosystem/Filesystem/fileSystem.graphql:43,58`; `me: User! @auth` at `graphql/schemas/Ecosystem/user.graphql:338`.
- Registration creates a default company per user through `CreateDefaultCompanyForUserAction` (`src/Kanvas/Auth/Actions/CreateUserAction.php:352`; `src/Kanvas/Companies/Actions/CreateDefaultCompanyForUserAction.php:23`), so in the default configuration one company is one person. The exception is apps that set `GLOBAL_USER_REGISTRATION_ASSIGN_GLOBAL_COMPANY` (`src/Kanvas/Enums/AppSettingsEnums.php:26`), which puts many users in one shared company.
- A file row records its uploader: `filesystem.users_id` (`database/migrations/2023_01_06_200643_create_filesystem_table.php:19`), set from the authenticated user in `FilesystemServices::upload()` (`src/Kanvas/Filesystem/Services/FilesystemServices.php:52`).
- `deleteFile` restricts non-admins to their own rows:

  ```php
  // app/GraphQL/Ecosystem/Mutations/Filesystem/FilesystemManagementMutation.php:290
  $filesystem = Filesystem::when(! auth()->user()->isAdmin(), function ($query) {
      $query->where('users_id', auth()->user()->getId())
          ->where('companies_id', auth()->user()->getCurrentCompany()->getId());
  })->where('uuid', $request['uuid'])->notDeleted()->firstOrFail();
  ```

  `deAttachFile` / `deAttachFiles` apply the same owner filter (`:107`, `:138`). `renameFile` (`:387-395`) and `editFile` (`:431-447`) also refuse a non-owner unless the caller is an admin. So *write* operations on a file are per-user. The hole is on the read side, below.

**Where it breaks for personal records.**

1. **There is no "list my files" query.** `User.files` (`graphql/schemas/Ecosystem/user.graphql:41`) resolves through `FilesystemQuery::getFileByGraphType()` → `entityFilesQuery()` (`app/GraphQL/Ecosystem/Queries/Filesystem/FilesystemQuery.php:24,150`), which joins `filesystem_entities` on `entity_id = $root->getKey()` and the `users` system-module id. A row created by `upload(file:)` is **not** attached to any entity, so it is invisible to `me.files`. A client would have to attach every upload to the Users system module itself before it could list it, and nothing in the API does that for it.
2. **Same-company users can read each other's attachments.** `entityFiles(entity: {systemModuleUuid, entityId})` calls `SystemModulesRepository::getEntityFromInput($input, auth()->user())` (`src/Kanvas/SystemModules/Repositories/SystemModulesRepository.php:179`). For the `Users` model that path does:

   ```php
   // src/Kanvas/SystemModules/Repositories/SystemModulesRepository.php:217
   if ($entity instanceof Users) {
       UsersRepository::belongsToCompany($entity, $user->getCurrentCompany());
   }
   ```

   Belonging to the same company is the only check — there is no "is this me" branch. Any authenticated user in the company who knows or obtains another member's user UUID can list that member's attached files. In a single-user company this is invisible; in a shared company (the `GLOBAL_USER_REGISTRATION_ASSIGN_GLOBAL_COMPANY` case, or any B2B tenant) it is a cross-user read. `UsersRepository::belongsToCompany()` also returns early for `isAppOwner()` (`src/Kanvas/Users/Repositories/UsersRepository.php:139-143`), so an app owner is never blocked.
3. **There is no per-record access control.** Nowhere in the filesystem surface is there a grant, share, or per-row ACL. Authorisation is exactly three coarse predicates: authenticated, same app, same company — plus "is admin", which bypasses the owner checks entirely.

**Implication.** "Personal" is currently enforced only by company boundary. That is correct for a one-person company and unsound for every other shape. A personal records organizer must not rely on it.

### 3.2 Document metadata and storage — **Partial**

**What exists.**

Three tables:

| Table | Migration | Shape |
|---|---|---|
| `filesystem` | `database/migrations/2023_01_06_200643_create_filesystem_table.php` | `id, companies_id, apps_id, users_id, name, path, url, size, file_type, created_at, updated_at, is_deleted` |
| `filesystem_entities` | `database/migrations/2023_01_06_200643_create_filesystem_entities_table.php` | `filesystem_id, entity_id (char 36), companies_id, system_modules_id, field_name, weight, is_deleted` |
| `filesystem_settings` | `database/migrations/2023_01_06_200643_create_filesystem_settings_table.php` + `2025_07_18_201059_add_public_flag_filesystem_column.php` | `name, value, is_public` |

The GraphQL type exposes `id, uuid, name, url, type, size, field_name, attributes, weight, settings, created_at` (`graphql/schemas/Ecosystem/Filesystem/fileSystem.graphql:1-15`).

Mutations: `upload` (`:59`), `multiUpload` (`:67`), `createFileSystem` (URL ingest), `attachFile` (`:71`), `deAttachFile` (`:75`), `deAttachFiles` (`:79`), `deleteFile` (`:83`), `renameFile` (`:95`), `editFile` (`:99`). Resolvers in `app/GraphQL/Ecosystem/Mutations/Filesystem/FilesystemManagementMutation.php`: `singleFile:214`, `multiFile:273`, `deleteFile:290`, `renameFile:387`, `editFile:431`.

Free-form metadata is available: `filesystem_settings` has no mutation, but code writes there — e.g. `filesystem.set('scribe_pdf_extracted', [...])` in `src/Domains/Intelligence/Agents/Neuron/Tools/Accounting/ExtractInvoiceDataTool.php:96`, gated by `PdfExtractionCustomFieldEnum::EXTRACTED = 'scribe_pdf_extracted'`. So the storage mechanism works; there is simply no client-facing write for it.

**What is missing or unsafe.**

- **Visibility is hard-coded `public`.** `FilesystemServices` passes `'visibility' => 'public'` on upload (`src/Kanvas/Filesystem/Services/FilesystemServices.php:78,87,139`). There is no signed-URL or presigned-URL path anywhere in `src/Kanvas/Filesystem` or `app/GraphQL/Ecosystem` — an uploaded document's `url` is a public object URL, and `url` is returned in the GraphQL type. Whatever the bucket policy happens to be in production, the application's intent is public.
- **No extension allow-list on the generic upload.** `singleFile` validates size only (`validateFileSize`, `FilesystemManagementMutation.php:196`). Any file type accepted by PHP uploads is stored.
- **No content checksum at the filesystem layer.** The only `content_sha256` in the schema belongs to Scribe's `pdf_ingest_log` (`database/migrations/Scribe/2026_06_14_000030_add_content_sha256_to_pdf_ingest_log.php`), and it exists for accounting-PDF dedup, not for record integrity.
- **No malware scanning.** No ClamAV, AV, or scan hook exists in `src/`, `app/`, or `config/`.
- **Orphaned bytes on parent delete.** `deleteFile` soft-deletes the `filesystem_entities` rows it can see for that file (`FilesystemManagementMutation.php:305-308`); deleting a *parent entity* does not cascade to its attachments, so `filesystem` rows and their stored bytes survive the record they belonged to.

**Implication.** Storage is real and usable. Metadata depth is adequate for a document list (name, type, size, field, weight, custom settings). Privacy, type restriction, integrity checking, and lifecycle hygiene are not.

### 3.3 Text extraction with provenance — **Partial**

**What is verified.**

- Extraction is implemented in `src/Kanvas/Filesystem/Services/FileTextExtractor.php`. `supportedExtensions()` (`:114`) covers plain text, code, CSV, JSON, PDF, Word and spreadsheets; `extensionForMimeType()` (`:135`) routes bytes to the right parser. PDFs go through a text-only parse (`extractPdf:255`) — **there is no OCR**, so a scanned document extracts to an empty string.
- Caps exist to bound the work: `MAX_ARCHIVE_MEMBER_BYTES = 16 * 1024 * 1024` (`:104`), `MAX_SPREADSHEET_ROWS = 2000` (`:88`), `MAX_SPREADSHEET_COLUMNS = 100` (`:95`).
- Chunking keeps document structure: `KnowledgeChunker` splits on blank lines and packs paragraphs up to `MAX_CHUNK_LENGTH = 1000` (`src/Domains/Intelligence/Knowledge/Support/KnowledgeChunker.php:9,14-21`), never cutting mid-word.
- Provenance is recorded per chunk. `KnowledgeIndexer::indexDocument()` writes `apps_id, companies_id, entity_type, entity_id, source_type, source_id, sourceType, sourceName` into each chunk's metadata, with the chunk id built as `"{$sourceType}:{$sourceName}:{$entityId}:{$index}"` (`src/Domains/Intelligence/Knowledge/Services/KnowledgeIndexer.php:75-91`). `EntityFilesIndexer` keys file documents by the file's UUID, so re-indexing replaces in place and a delete can prune by the same key (`src/Domains/Intelligence/Knowledge/Services/EntityFilesIndexer.php:12-15,49-59`).
- A read surface for *structured* extraction exists for accounting PDFs: `scribePdfIngestLogs` returns `extracted_payload`, `confidence`, `document_type`, `classification_reasoning`, `status`, and the linked `filesystem` (`graphql/schemas/Scribe/pdfIngest.graphql:28-69`; model `src/Domains/Scribe/PdfIngest/Models/PdfIngestLog.php:46`, columns from `database/migrations/Scribe/2026_06_14_000024_create_pdf_ingest_log_table.php`).
- Files can be read inside agent turns with the same scoping as any other company resource: `ReadFileTool::resolveFile()` uses `->fromApp($this->app)->fromCompany($this->company)` (`src/Domains/Intelligence/Agents/Neuron/Tools/Common/ReadFileTool.php:253-263`).

**Where it breaks.**

1. **Extracted text is never persisted anywhere a client can read.** Extraction output goes to exactly three places: an in-memory string for the agent tool, Typesense chunks, and the accounting-specific `pdf_ingest_log`/`filesystem_settings` payload. There is no column, setting, or query that answers "give me the text of file X". For a records organizer, the text is a first-class artifact and it is currently write-only.
2. **Indexing is per-app opt-in and off by default.** `KnowledgeComponents::knowledgeEnabled()` reads `neuron_lead_rag_enabled` with no default (`src/Domains/Intelligence/Knowledge/Services/KnowledgeComponents.php:83`; key in `src/Domains/Intelligence/Knowledge/Enums/KnowledgeConfigurationEnum.php:5`). Chunks also live only in Typesense — there is no relational copy, so a Typesense outage, a collection reset, or a missing credential means the derived text is gone and must be recomputed.
3. **Provenance stops at the chunk.** It identifies app, company, entity type/id, source type, and source name (file UUID) — good. It does **not** record page number, byte/character offset into the source, the extractor version, or the parser used. "Which part of which document said this" is answerable at the document level, not the excerpt level.
4. **No extraction provenance for a failed or empty extraction.** A scanned PDF returns `''` from `extractPdf` and nothing distinguishes "empty document" from "could not read this document".

### 3.4 Correction — **Gap**

**What is editable today.**

- File *metadata*: `renameFile(id, name)` (`fileSystem.graphql:95`), `editFile(uuid, input: FilesystemEntityUpdateInput)` which changes `field_name` and `weight` (`:99`, input at `:25-28`), `deAttachFile`/`deAttachFiles`.
- Arbitrary key/value metadata: `filesystem_settings` (no client mutation, but writable from code).
- Business-entity fields: standard `updateX` mutations plus `custom_fields` on the update inputs (`graphql/schemas/Ecosystem/user.graphql:150,178`, and the same pattern on company, message, agent, product, etc.).
- Notes for human annotation: the notes channel used across entities.
- A *specific* correction workflow exists for accounting: `AmendInvoiceAction` (`src/Domains/Scribe/Invoices/Actions/AmendInvoiceAction.php`) with an append-only `Invoice::amendmentHistory()` (`src/Domains/Scribe/Invoices/Models/Invoice.php:276-306`). It is the pattern to copy, not a facility a records product can reuse.

**Where it breaks.**

1. **Extracted text cannot be corrected.** There is no write path to the derived text at all, so a mis-OCR'd or mis-parsed passage can only be corrected by deleting the file and uploading a different one — which also discards the record's identity and history.
2. **There is no revision history for a file or its metadata.** `renameFile` and `editFile` overwrite in place; `filesystem` has `updated_at` and nothing else. There is a general `audits` table in the schema (`database/migrations/2023_01_06_200643_create_audits_table.php`) with `entity_id, model_name, users_id, ip, type, created_at` — but **no model and no writer exist for it**; `grep` for the table finds only the migration and an unrelated connector's own client-side `audits` table. It is a vestigial table, not an audit trail.
3. **No way to distinguish a corrected record from an original.** Needed for any records product where the user must be able to trust what they are looking at.

### 3.5 Deletion and export lifecycle — **Gap**

**Deletion that works.**

- Single file: `deleteFile(uuid)` verifies ownership, asserts fiscal-retention guards, deletes the bytes, soft-deletes every `filesystem_entities` row, soft-deletes the `filesystem` row, and fires a `file.deleted` workflow (`FilesystemManagementMutation.php:290-320`, `fireFileDeletedWorkflow:177`). The retention assertion runs before the bytes go (`:300`), which is the correct order.
- Company knowledge documents: `CompanyKnowledgeService::deleteDocument()` deletes the file, soft-deletes the entities, and dispatches `PruneCompanyKnowledgeJob` to remove the Typesense chunks (`src/Domains/Intelligence/Knowledge/Services/CompanyKnowledgeService.php:91-114`). This is the closest thing to an end-to-end delete-and-derive cleanup in the codebase.
- Fiscal documents are protected from deletion by `FiscalFileRetention`, which hangs listeners on `softDeleting`/`deleting` for `Filesystem` and `FilesystemEntities` and refuses (`src/Domains/Scribe/ElectronicInvoicing/Support/FiscalFileRetention.php:29-50`).

**Deletion that does not work.**

1. **Account deletion is a request that is never processed.** `requestDeleteAccount` (`user.graphql:318`) writes a `request_deleted_accounts` row and soft-deletes the user's messages (`src/Kanvas/Users/Actions/RequestDeleteAccountAction.php:22-45`). The processor, `DeleteUsersRequestedCommand`, is scheduled daily at `app/Console/Kernel.php:73` **with no `apps_id` argument**, and its own guard exits immediately in that case:

   ```php
   // app/Console/Commands/Ecosystem/Users/DeleteUsersRequestedCommand.php:32-35
   if (! isset($app)) {
       $this->info('Delete users request process is disabled');
       return;
   }
   ```

   Even when it is run manually with an `apps_id`, it only calls `$user->associateUsers()->deActive()` (`:50`) — a status flip on `users_associated_apps`. There is no purge of files, messages, leads, notes, or custom fields, and the request row is never marked processed.
2. **The date predicate looks inverted.** `->where(DB::raw('DATEDIFF(request_date, CURDATE())'), '>', $days)` (`:44`) computes `request_date - today`, which is negative for any past request, so it can never exceed a positive `$days`. Flagged as an observation; not fixed here.
3. **No export of a person's data.** Export in this codebase is a *business reporting* feature: `exportProducts`, `exportMechanicOrders`, `exportMechanics`, `exportPayments`, `exportFinancialReport`, order-transition export (`graphql/schemas/Inventory/product.graphql:361`, `graphql/schemas/Connector/movipass.graphql:119,150`, `app/GraphQL/Souk/Queries/OrderStatsQuery.php:146,171`). None of them is "export everything you hold about this person". `ExportRecordsTool` is an LLM tool for CRM record export, not a subject-access export.
4. **Deleting a parent entity leaves its files behind** (see 3.2), so "delete the record" today does not delete the document.
5. **Knowledge pruning is not a default.** Chunk cleanup runs only if a per-app command has wired `PruneKnowledgeDocumentActivity` into a rule (`app/Console/Commands/Intelligence/Knowledge/SetupAgentKnowledgeIndexingRuleCommand.php:46`). An app that indexes without wiring this keeps deleted documents' chunks in Typesense.

**Implication.** A records organizer needs a delete that reaches bytes, relational rows, derived vectors, and backups, and an export that reaches everything held about the subject. Neither exists.

### 3.6 Consent and retention — **Gap**

**Consent that exists is marketing consent.**

`src/Domains/Guild/Customers/Enums/ConsentConfigurationEnum.php` defines `do_not_contact`, `do_not_contact_reason`, `do_not_contact_at`, `do_not_contact_source`, `do_not_contact_match`, plus confirmation-message keys. Enforcement runs through `ApplyDoNotContactAction` (`src/Domains/Guild/Customers/Actions/ApplyDoNotContactAction.php:33`), keyword detection on inbound messages, `Contact.is_opt_out` (`src/Domains/Guild/Customers/Models/Contact.php:30,84,95,193`), and guards in `SendEmailTool`/`SendSmsTool` and the campaign recipient resolver. Per `src/Domains/Guild/Customers/CLAUDE.md`, a STOP on one channel silences all channels person-wide — that part is well built.

That is **contact suppression**, not **data-processing consent**. Absent everywhere:

- no record of consent to *store and process* a document, with a policy version and a timestamp;
- no withdrawal of processing consent distinct from opting out of messages;
- no consent gate on extraction, indexing, or agent access to a document;
- no provenance of *who* consented and *how* (the UI text shown).

The nearest adjacent records are business acceptances, not privacy consent: `document_acceptances` (`database/migrations/Scribe/2026_11_09_000001_create_document_acceptances_table.php`, model `src/Domains/Scribe/Delivery/Models/DocumentAcceptance.php`) records a customer accepting a quote revision — signer, SOW hash, IP, user agent. `CrmConsent` (`src/Domains/Connectors/SalesAssist/DataTransferObject/Crm/CrmConsent.php:10`) is a *translation* of a third-party CRM's opt-out booleans. `SoftPullFromLeadActivity` carries a comment that a credit-bureau pull should only run where the customer consented (`src/Domains/Connectors/OfferLogix/Workflow/SoftPullFromLeadActivity.php:28`) — a warning, not a check.

**Retention that exists is per-domain and does not cover records.**

| Data | Retention | Where |
|---|---|---|
| Agent memory | 365 days default, per-app override | `KnowledgeComponents::memoryRetentionDays()` (`src/Domains/Intelligence/Knowledge/Services/KnowledgeComponents.php:107-115`), pruned by `PruneAgentMemoryCommand` |
| Ledger events | 7 days hot, then archived | `src/Domains/NervousSystem/Ledger/Actions/ArchiveOldEventsAction.php:66-67` (`config('nervous-system.ledger.retention_days', 7)`) |
| Fiscal documents | 10 years, immutable | `src/Domains/Scribe/ElectronicInvoicing/Regimes/Dr/DrFiscalRegime.php:45` (`RETENTION_YEARS = 10`); enforced by `Support/FiscalFileRetention.php` |
| **Personal records / files** | **none** | — |

There is no filesystem retention or purge command; `find app/Console/Commands -name "*Prune*"` returns model-cache tags, agent memory, and lead-message knowledge only. A soft-deleted `filesystem` row and its bytes stay forever.

## 4. What a client can actually call today

If a mobile client tried to ship a minimal records organizer on the current API, this is the complete usable surface:

```graphql
# upload — returns a Filesystem row, NOT attached to anything
mutation { upload(file: $file) { id uuid name url size type } }

# attach an uploaded file to an entity you own
mutation { attachFile(input: {
  filesystem_uuid: "...", field_name: "document",
  system_module_uuid: "...", entity_id: "..." }) }

# list files attached to an entity (company-scoped, peer-readable)
query { entityFiles(entity: { systemModuleUuid: "...", entityId: "..." }) { data { uuid name url size } } }

# rename / re-field / detach / delete
mutation { renameFile(id: 1, name: "new name") { uuid name } }
mutation { editFile(uuid: "...", input: { field_name: "x", weight: 1 }) }
mutation { deAttachFile(uuid: "...") }
mutation { deleteFile(uuid: "...") }
```

Limits a client must work around: files are public-visibility; un-attached uploads cannot be listed; `entityFiles` requires knowing a system-module UUID and an entity id; there is no text, no search over text, no correction, no export, and no consent model.

## 5. Gaps, ranked by what blocks the product

| Rank | Gap | Blocks |
|---|---|---|
| 1 | No private-by-default storage or signed access | Any health-adjacent document. Public URLs make every other control cosmetic. |
| 2 | No per-user record ownership/ACL on files | "Personal" records in any multi-user company. |
| 3 | No readable, persisted extracted text | Search, review, correction — the whole reason to digitise a document. |
| 4 | No correction or revision history for text | User trust; the record becomes wrong and stays wrong. |
| 5 | No end-to-end delete (bytes + rows + vectors) | Data-subject deletion. |
| 6 | No subject export | Portability, and the ability to leave. |
| 7 | No processing consent record | Lawful basis for storing the documents at all. |
| 8 | No retention policy or automation for records | Minimisation; unbounded accumulation. |
| 9 | No extension allow-list, no scan, no checksum | Upload safety and integrity. |
| 10 | No OCR | Scanned documents extract to nothing. |

## 6. Smallest change set that would make this shippable

Ordered so each step is independently useful. This is a recommendation, not work performed.

1. **Private storage + signed access.** Stop hard-coding `visibility => 'public'`; serve a short-lived signed URL from a resolver instead of storing a public `url`. Nothing else on this list matters until this lands.
2. **A first-class record entity.** Either a `records` domain (owner `users_id`, `apps_id`, `companies_id`, status, consent reference) with files attached to it, or a hard rule that every personal file is attached to the owner's `Users` system-module entity *and* `entityFiles` refuses an entity that is not the caller. The second is smaller but keeps privacy as a convention rather than a constraint; the first is the honest shape.
3. **Persist extracted text.** A `filesystem_text` (or per-record text) row with `text`, `extractor_version`, `source_sha256`, `status` (`ok`/`empty`/`failed`) and offsets/page numbers per chunk. This turns extraction from a transient agent convenience into a record artifact — and gives correction something to correct.
4. **An edit path for text + a revision row** written on every text/metadata change. Do not resurrect `audits`; write the history where the record lives.
5. **Delete that reaches everything.** A single action that removes bytes, `filesystem`, `filesystem_entities`, persisted text, and dispatches the chunk prune unconditionally — plus a cascade from the parent record.
6. **Subject export.** One query returning the record, its metadata, its text, and its file references as an archive. Reuse the existing export-job pattern from Inventory/Souk rather than inventing one.
7. **Processing consent.** A `consents` row per (subject, purpose, policy version, granted_at, withdrawn_at) with a check on the ingest path, kept separate from `ConsentConfigurationEnum`'s marketing suppression.
8. **Record retention.** A retention window per purpose and a prune command on the scheduler, modelled on `PruneAgentMemoryCommand`.

Items 1, 3 and 5 are the ones that cannot be deferred; 2 decides whether the product is honestly "personal".

## 7. Unknowns — deployment facts not answerable from this repository

1. **The plan for this work was not provided.** The scope came from the ticket's six areas.
2. **Bucket and CDN configuration.** Whether the production bucket actually permits public reads on a guessed object path, and whether a CDN caches them, is deployment configuration. The application requests `public`; the bucket may or may not honour it.
3. **Database and object encryption at rest.** Not visible in code.
4. **Which apps have enabled knowledge/RAG** (`neuron_lead_rag_enabled`) and therefore which tenants have extracted text in Typesense at all. Also whether each has wired `PruneKnowledgeDocumentActivity`.
5. **Whether `DeleteUsersRequestedCommand` is run manually anywhere** outside the scheduler, with an `apps_id`.
6. **Runtime behaviour of anything above.** Not executed locally; PHP and the test stack are unavailable on this machine.

## 8. Evidence index

| Claim | Location |
|---|---|
| Public visibility on upload | `src/Kanvas/Filesystem/Services/FilesystemServices.php:78,87,139` |
| Delete path (retention check → bytes → rows) | `app/GraphQL/Ecosystem/Mutations/Filesystem/FilesystemManagementMutation.php:290-320` |
| Upload metadata / owner column | `database/migrations/2023_01_06_200643_create_filesystem_table.php:19` |
| Attachment table shape | `database/migrations/2023_01_06_200643_create_filesystem_entities_table.php` |
| Settings `is_public` flag | `database/migrations/2025_07_18_201059_add_public_flag_filesystem_column.php` |
| GraphQL filesystem surface | `graphql/schemas/Ecosystem/Filesystem/fileSystem.graphql:43-103` |
| Upload / delete / rename / edit resolvers | `app/GraphQL/Ecosystem/Mutations/Filesystem/FilesystemManagementMutation.php:214,290,387,431` |
| Size-only validation | `app/GraphQL/Ecosystem/Mutations/Filesystem/FilesystemManagementMutation.php:196-211` |
| Company-only file listing | `app/GraphQL/Ecosystem/Queries/Filesystem/FilesystemQuery.php:24,101,150` |
| `User.files` / `me` | `graphql/schemas/Ecosystem/user.graphql:41,338` |
| Peer read via same-company check | `src/Kanvas/SystemModules/Repositories/SystemModulesRepository.php:179,217` |
| Extraction formats and caps | `src/Kanvas/Filesystem/Services/FileTextExtractor.php:88,95,104,114,135,255` |
| Chunk size | `src/Domains/Intelligence/Knowledge/Support/KnowledgeChunker.php:9` |
| Chunk provenance metadata | `src/Domains/Intelligence/Knowledge/Services/KnowledgeIndexer.php:32-91` |
| File keyed by UUID for replace/prune | `src/Domains/Intelligence/Knowledge/Services/EntityFilesIndexer.php:12-15,49-59` |
| Knowledge opt-in switch | `src/Domains/Intelligence/Knowledge/Services/KnowledgeComponents.php:83`; `Enums/KnowledgeConfigurationEnum.php:5` |
| Agent memory retention 365d | `src/Domains/Intelligence/Knowledge/Services/KnowledgeComponents.php:107-115` |
| Knowledge document delete + prune | `src/Domains/Intelligence/Knowledge/Services/CompanyKnowledgeService.php:91-114` |
| Prune wiring is per-app | `app/Console/Commands/Intelligence/Knowledge/SetupAgentKnowledgeIndexingRuleCommand.php:46` |
| Accounting extraction read surface | `graphql/schemas/Scribe/pdfIngest.graphql:28-69`; `src/Domains/Scribe/PdfIngest/Models/PdfIngestLog.php:46` |
| Extraction written to file settings | `src/Domains/Intelligence/Agents/Neuron/Tools/Accounting/ExtractInvoiceDataTool.php:96` |
| Agent file read scoping | `src/Domains/Intelligence/Agents/Neuron/Tools/Common/ReadFileTool.php:253-263` |
| Accounts deletion request | `src/Kanvas/Users/Actions/RequestDeleteAccountAction.php:22-45` |
| Deletion processor no-ops when scheduled | `app/Console/Commands/Ecosystem/Users/DeleteUsersRequestedCommand.php:32-35,44,50`; `app/Console/Kernel.php:73` |
| Fiscal retention/immutability | `src/Domains/Scribe/ElectronicInvoicing/Support/FiscalFileRetention.php:29-50`; `Regimes/Dr/DrFiscalRegime.php:45` |
| Marketing consent keys | `src/Domains/Guild/Customers/Enums/ConsentConfigurationEnum.php`; `Actions/ApplyDoNotContactAction.php:33`; `Models/Contact.php:30,84,95,193` |
| DNC enforced at send time | `src/Domains/Intelligence/Agents/Neuron/Tools/CRM/SendEmailTool.php:107`; `SendSmsTool.php:78` |
| Ledger hot retention default | `src/Domains/NervousSystem/Ledger/Actions/ArchiveOldEventsAction.php:66-67` |
| Business (not privacy) acceptance record | `database/migrations/Scribe/2026_11_09_000001_create_document_acceptances_table.php` |
| Third-party CRM opt-out translation | `src/Domains/Connectors/SalesAssist/DataTransferObject/Crm/CrmConsent.php:10` |
| Credit-bureau consent is a comment only | `src/Domains/Connectors/OfferLogix/Workflow/SoftPullFromLeadActivity.php:28` |
| Vestigial `audits` table, no writer | `database/migrations/2023_01_06_200643_create_audits_table.php` (only reference in `src/`/`app/`) |

## 9. Verification

**Not run locally; verified by CI only.** PHP, Composer and the project's Docker stack are not installed on this machine, so no test suite, static analysis, or style check was executed. This change adds one Markdown file under `docs/` and touches no PHP, schema, or configuration, so there is no behaviour to test.
