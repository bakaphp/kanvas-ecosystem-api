# Yusen Logistics — 3PL inventory discrepancy report

Loads when work touches `src/Domains/Connectors/Yusen/`.

Yusen runs a customer's 3PL warehouse and pushes a Manhattan Associates ILSNET **Item Balance**
XML. We parse it, compare it against Kanvas and against NetSuite, and email the differences.

**This connector writes no stock.** See "Why it writes nothing" below before changing that.

---

## What Yusen posts to us

Production receiver:

```
POST https://graphapi.kanvas.dev/v1/receiver/{{UUID}}
```

The receiver accepts the document **either** way. Both are handled by
`YusenInventoryBalanceWebhookJob::execute()`; nothing about the request needs configuring on
Yusen's side beyond the URL.

### Option A — multipart upload (preferred)

```bash
curl -X POST \
  "https://graphapi.kanvas.dev/v1/receiver/{{UUID}}" \
  -F "file=@Item Balance-2026-08-21@07-00-02.xml;type=application/xml"
```

- The form field name does **not** matter (`file`, `upload`, `document` — all fine). The receiver
  pipeline stores every uploaded file and the job picks the first whose **name ends in `.xml`**.
- Preferred because the filename survives and lands on the report as `file_name`, which is how an
  operator ties a report back to a specific drop.
- Non-XML attachments alongside it (a cover PDF, a signature image) are ignored, not an error.

### Option B — raw XML body

```bash
curl -X POST \
  "https://graphapi.kanvas.dev/v1/receiver/{{UUID}}" \
  -H "Content-Type: application/xml" \
  --data-binary @"Item Balance-2026-08-21@07-00-02.xml"
```

- The body must contain the literal `<WMWROOT` or it is rejected as `no_xml_payload` — that guard
  is what stops an unrelated JSON webhook aimed at the same URL from being parsed as a balance.
- **The filename is lost this way**, so `file_name` on the report is `null`.

### Minimal body shape

```xml
<WMWROOT xmlns="http://www.manh.com/ILSNET/Interface"><WMWDATA><WMFWUpload>
  <Date>2026-08-21T07:00:03.8640684Z</Date>
  <GroupIndex>1</GroupIndex>
  <Id>0bfb0c40-9503-4956-ba53-1bd878e72ad6</Id>   <!-- upload identity -->
  <NumGroups>1</NumGroups>
  <NumRecs>96</NumRecs>
  <Inventories>
    <Inventory>
      <InternalID>20521091</InternalID>            <!-- identity of THIS lot record -->
      <Warehouse>Carson1</Warehouse>
      <Status>Available</Status>
      <AllocatedQty>9.00000</AllocatedQty>
      <InTransitQty>9.00000</InTransitQty>
      <SuspenseQty>0.00000</SuspenseQty>
      <SKU>
        <Item>4511338000014</Item>                 <!-- EAN-13, matched to variants.barcode -->
        <Desc>Classic Marker Cool Gray No.0</Desc>
        <Quantity>110.00000</Quantity>             <!-- on-hand FOR THIS RECORD -->
        <Style/><Color/><Size/>
      </SKU>
    </Inventory>
  </Inventories>
</WMFWUpload></WMWDATA></WMWROOT>
```

### Responses

| Situation | Status | Body |
|---|---|---|
| Accepted (receiver is `run_async`) | 200 | `{"message":"Receiver processed"}` |
| Accepted (sync receiver) | 200 | `{"message":"Receiver processed","dispatched":true,"source":"upload"...}` |
| No XML found in the request | 200 | `{"dispatched":false,"reason":"no_xml_payload"}` |
| Receiver switched off | 200 | `{"message":"Receiver is not active"}` |
| Bad/unknown UUID | 404 | `{"message":"Receiver not found"}` |

**Run the receiver async** (`receiver_webhooks.run_async = 1`). Parsing plus two reconciliation
legs takes far longer than a WMS uploader will wait for an ack, and a sync receiver puts that whole
cost inside Yusen's timeout.

### Limits and auth — read before handing out the URL

- **The URL is the credential.** `YusenInventoryBalanceWebhookJob` does not override
  `authenticateRequest()`, so it inherits the default "trust the request". Anyone holding the UUID
  can post a balance. Treat it like a secret; rotate by creating a new receiver.
- PHP accepts up to 512M (`docker/php.ini`) / 900M (`docker/php-fpm.conf`). A 413 before that means
  nginx's `client_max_body_size` at the edge is the tighter limit.
- Re-posting the same file is **not** deduplicated. It is harmless (nothing is written, the report
  is just recomputed) but it does re-send the email. Idempotency arrives with the movement ledger,
  which keys on `external_source` + `idempotency_key`.

---

## Reading the report

Stored on `receiver_webhook_calls.results` and emailed to everyone holding the **Managers** role
for that company + app.

**The report arrives on the receiver row in two stages.** `ProcessWebhookJob` writes only what the
webhook returned — `{"dispatched":true,"source":"raw_body","filesystem_id":null}` — because the
real work happens in a separate queued job. That job merges the finished report into the same row
when it completes, so a row still showing only `dispatched` means the queued job has not run yet,
or failed (check `failed_jobs` and Sentry, not the receiver's `exception` column — that one only
covers the webhook).

| Field | Meaning |
|---|---|
| `external_id` | Yusen's `WMFWUpload > Id` for the upload |
| `total_records` / `total_items` | 5 records collapsing to 4 items means lots were summed |
| `multi_record_items` | **Watch this** — see the summing assumption below |
| `by_source` / `by_type` | Counts per comparison source and per discrepancy class |
| `source_errors` | Per-source failure message; that source is absent from `by_source` |
| `kanvas_warehouse_configured` | `false` = no warehouse resolved, Kanvas leg was skipped |
| `rows` | Capped at 200 on the stored copy, with `rows_omitted` saying how many were dropped |

Discrepancy classes: `QUANTITY_MISMATCH`, `MISSING_IN_KANVAS`, `MISSING_IN_NETSUITE`,
`MISSING_IN_YUSEN`, `MISSING_IN_SOURCE` (fallback for a source with no dedicated case).

---

## Hard rules specific to this tree

### It writes no stock, and a per-source warehouse is not the fix

The first cut wrote Yusen's count into a dedicated "Yusen Carson1" warehouse so it wouldn't
overwrite what `PullNetSuiteProductStockAction` puts in `products_variants_warehouses.quantity`.
That double-counts: `Variants::setTotalQuantity()` sums **every** warehouse row with no notion of
source, and that total feeds `getTotalQuantity()` — consumed by every agent inventory tool
(`InventorySearchTool`, `ListAvailableProductsTool`, `VariantSearchTool`, Neuron *and* Laravel),
`Products.php:269`, the recommendation presenter, and the Recombee index. Agents would quote
customers double stock.

`products_variants_warehouses` is live balance state for **one** source of truth. A 3PL physical
count is a different kind of fact. It belongs in the movement ledger
(`docs/inventory-movement-ledger-plan.md`) as a `cycle_count` batch with
`external_source = wms-3pl` — that plan already names `wms-3pl` as its example value and gives
idempotency and history for free. **Do not reintroduce a warehouse write or a private snapshot
table.**

### `SKU/Quantity` is per-record, so lot rows sum

One item can arrive on several `<Inventory>` records (different lots/receipts) and we add them up,
de-duplicating by `InternalID`. If Yusen ever repeats an *item-level* total on every lot row
instead, that silently double-counts. `multi_record_items` counts items that arrived on more than
one record and is logged whenever non-zero — that is the tripwire. Confirm the semantics with
Yusen before trusting a file where it is high.

### Adding a comparison source is one class

The comparator has no per-source branching. Implement `Contracts/InventoryQuantitySource`
(`key()`, `quantities()`, `describe()`) and pass it in; the same two-way diff runs against it.
Sources return their **whole** position, not per-item answers, because the report works in both
directions and a per-item lookup can only answer half of it.

Give a new source its own `MISSING_IN_<X>` case in `DiscrepancyTypeEnum` plus an arm in
`DiscrepancyTypeEnum::missingFor()`; without one it falls back to `MISSING_IN_SOURCE`.

### No GraphQL of its own

Setup runs through the shared `integrationCompany` mutation, which reads `handler` off the
`integrations` row and calls `YusenHandler::setup()`. A `yusenSetup` mutation would be a second way
to do the same thing — don't add one.

### The test fixture is synthetic on purpose

`tests/Connectors/Yusen/fixtures/item-balance.xml` uses invented `999`-prefix barcodes, a fictional
brand, `WHSE1`/`WHSE2` and service-account user stamps. The real file carries operator names and
the customer's catalog — **never paste a real Yusen file into the repo.** The fixture deliberately
encodes: an item split across two lot records with different statuses, a serialised item, an item
with no `<Size>`, an item whose `<Desc>` is its own code, and a second warehouse. Keep those when
editing it or the parser tests stop covering what they claim to.

---

## Config keys

Company-first, app-second (`YusenSettings`).

| Key | Meaning |
|---|---|
| `yusen_primary_warehouse_id` | Warehouse the Kanvas leg compares against. Falls back to the company's default warehouse. |
| `yusen_match_field` | `barcode` (default) or `sku` — which variant column `<Item>` matches |
| `yusen_netsuite_saved_search_id` | Defaults to `576`, the same saved search `PullNetSuiteProductStockAction` uses |
| `yusen_quantity_tolerance` | Absolute units tolerated before a delta counts (default 0) |
| `yusen_reconcile_with_netsuite` | Toggle the NetSuite leg (default true) |

## Deploy

```bash
php artisan migrate --path database/migrations/Workflow/ --database workflow
php artisan kanvas:workflow-sync-actions          # registers the receiver action
```

Then create the receiver (`kanvas:create-receiver-workflow`, choosing
**Yusen Inventory Balance Receiver**), set it `run_async`, and hand Yusen the URL.

### Recipients are the Managers role, not a config field

`UsersRepository::getCompanyAppUserByRole($company, $app, RolesEnums::MANAGER->value)`. A
per-company list of user ids goes stale the moment somebody joins or leaves and nobody remembers to
update it; role membership is already maintained.

**Use `RolesEnums::MANAGER->value` (`Managers`, plural), never the literal `'Manager'`.** The roles
table has 97 `Managers` rows across apps and exactly one `Manager` — code hardcoding the singular
(`Intellicheck/Actions/VerifyPeopleIdAction`, `Elead/Actions/AddOutBoundPhoneCallActivityToLeadAction`)
silently resolves to nobody for almost every app.

If the role isn't bootstrapped for the app, the send logs and returns — no Sentry noise, and the
report still lands on the webhook call.

**The `yusen-discrepancy-report` email template row must exist**, same as Apollo's
`apollo-daily-report`. Without it the send throws — caught and reported to Sentry, so the report
still lands on the webhook call, but nobody gets mail.

Backfill a file that arrived out of band:

```bash
php artisan kanvas:connectors:yusen-inventory-report {app_id} {company_id} /path/to/balance.xml --sync
```

Use `--sync` for large files: the async path serialises the whole XML into the queue payload.
