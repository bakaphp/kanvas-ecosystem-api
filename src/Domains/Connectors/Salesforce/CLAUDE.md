# Salesforce Connector — Kanvas Ecosystem API

Loads when work touches `src/Domains/Connectors/Salesforce/`. See also
`src/Kanvas/Filesystem/CLAUDE.md` for the generic `FilesystemMapper`/`ApplyFilesystemMapperAction`
mechanism this connector's custom-object handling is built on.

## Standard objects vs. custom objects

- **Standard objects** (`Lead`, `Contact`, `Account`, `Opportunity`) — hardcoded, one `Pull*Action`
  each, called directly from `SalesforceOutboundMessageWebhookJob::dispatchToAction()`. These are the
  same for every Salesforce org, so hardcoding them is correct — don't "genericize" this part.
- **Custom objects** (anything else — a tenant's own `__c`-suffixed object) — **never** hardcode the
  object name in this connector's shared files. A custom object's name and fields are
  tenant-specific; the only tenant-agnostic thing to build is wiring that reads a `FilesystemMapper`
  id from configuration and hands off to `ApplyFilesystemMapperAction`.

## Real-time custom-object import (`SalesforceOutboundMessageWebhookJob`)

One `ReceiverWebhook` row per Salesforce object (Outbound Message), differentiated by
`configuration['salesforce_object']` — this key already existed for the 4 standard objects. For any
custom object, add `configuration['mapper_id']` to that same receiver row:

```json
{ "salesforce_object": "Location__c", "mapper_id": 63 }
```

`dispatchToAction()`'s `default` arm (`applyMapper()`) reads it, loads the mapper, and calls
`ApplyFilesystemMapperAction` with a `$relatedRecordFetcher` closure that does a **live** SOQL query
(the webhook only carries the one record that changed, never its linked record in the same payload).

**That closure is the one place in this whole feature that builds a raw query string from
mapper-configured values (`link.source_object` / `link.match_field`) and payload data (the primary
id).** It validates the two identifiers with `assertValidSoqlIdentifier()` (alphanumeric/underscore
only — an identifier can't be escaped, only accepted or rejected) and escapes the literal id with
`escapeSoqlLiteral()` before interpolating — same pattern as `SalesforceSchemaQuery` uses for its
schema-browser queries, duplicated locally rather than shared (they're `private` methods on an
unrelated GraphQL resolver class). Don't interpolate any of these three values into a SOQL string
without going through both guards first — the primary id comes straight off an external webhook
payload.

## Setup checklist for a new custom-object mapping

1. Build the field list with the schema browser (`salesforceObjects` / `salesforceObjectFields` /
   `salesforceRecords` GraphQL queries) — don't ask the client for field names by hand.
2. Create a `FilesystemMapper` per target entity (`createFilesystemMapper`), `system_module_id`
   pointing at the Kanvas entity (Products / People today), `has_header: false` (no file — see
   `src/Kanvas/Filesystem/CLAUDE.md` for why that's allowed).
3. If the primary entity has a related one (a property's broker), add `configuration.links` on the
   *primary* mapper's create/update call, referencing the secondary mapper's id — see
   `src/Kanvas/Filesystem/CLAUDE.md` for the exact shape.
4. Wire the receiver: `updateReceiverWebhook` with
   `configuration: { salesforce_object: "<Object__c>", mapper_id: <id> }` (replaces `configuration`
   wholesale — include `salesforce_object` even if unchanged).

No new GraphQL mutation needed for any of this — everything above already exists generically.
