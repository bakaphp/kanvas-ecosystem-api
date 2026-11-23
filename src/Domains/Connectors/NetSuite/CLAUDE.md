# NetSuite import — technical reference

How the on-demand NetSuite → Kanvas import works: what each entity does, how locations are mapped,
what has to be configured per tenant, and the known limits.

Connecting NetSuite (`createIntegrationCompany`) only stores credentials; it imports nothing. The user
triggers the import, and running it again is a resync.

## Pieces

| Piece | Where |
|---|---|
| Schema | `graphql/schemas/Connector/netsuite.graphql` |
| Resolvers | `app/GraphQL/Connector/NetSuite/` |
| Products / customers / stock import | `Actions/ImportNetSuiteDataAction.php` |
| Location → warehouse mapping | `Actions/ImportNetSuiteLocationsAction.php`, `Services/NetSuiteLocationWarehouseService.php` |
| The account's locations, location join filter | `Services/NetSuiteLocationService.php` |
| Chunked job | `Jobs/ImportNetSuiteDataJob.php` (queue `imports`) |
| Import status | `Services/NetSuiteImportStatusService.php` |
| SOAP search pagination | `Client::searchAll()` |

Paths relative to `src/Domains/Connectors/NetSuite/` except the first two.

## Per-company requirements

- NetSuite connected. Credentials live on the **app** (`NET_SUITE_ACCOUNT_CONFIG`): every company of an
  app shares one NetSuite account.
- Inventory set up (region and default warehouse). Without a default warehouse, mapping locations fails.
- At least one mapped location for `PRODUCTS` and `STOCK`. The mutation checks it before queuing
  (`NetSuiteLocationWarehouseService::assertMapped()`): with no location the price refresh has nothing
  to read from, so every product would land at price 0 and count as failed.
- A worker on the `imports` queue (present in all three `docker-compose` files).

## Locations

NetSuite keeps each item's quantity **per location**. Each imported location is mirrored by a Kanvas
warehouse through the warehouse's `NET_SUITE_LOCATION_ID` custom field; the price refresh, the stock
import and quotes all read it from there.

Two ways to map them, same rules (`ImportNetSuiteLocationsAction`):

- **While importing products** with `location_ids` (`ensureMapped()`): the location the default warehouse
  already mirrors stays there; on a company with none mapped, the **first one chosen** goes onto the
  default warehouse. The rest become new warehouses named after the location.
- **On its own**, with `netsuiteImportLocations(default_location_id, location_ids)`: same, with an
  explicit default. Use it to change the default.

Rules:

- The default goes onto the **existing default warehouse**, never a new one: current products sit there
  and `VariantService` creates new ones there.
- A location is never mirrored by two warehouses: every warehouse's stock adds to the product total
  (`Variants::setTotalQuantity()`), so it would count twice.
- Only active locations of the account are accepted.
- Running it again never duplicates warehouses.
- It also writes `NET_SUITE_DEFAULT_WAREHOUSE` on the company (the default location's id): the fallback
  the price refresh uses for a variant in an unmapped warehouse.

`suggested` in `netsuiteLocations` is `type = warehouse` and "Make Inventory Available". It is only a
hint: on the account tested, "In Transit", "Other" and "Amazon" are flagged as available inventory, and
"Chicago" is a warehouse-typed location with no available inventory. The user decides.

## Products

Lists, then processes in chunks of 100, one job per chunk queuing the next.

**Which items:**

| Input | Search |
|---|---|
| `saved_search_id` (or setting `NET_SUITE_PRODUCTS_SAVED_SEARCH_ID`) | That saved search. |
| `location_ids`, no saved search | Basic search of active inventory items joined to those locations. One record per item even when several locations carry it. |
| Neither | Every active inventory item. |

Inventory items only: `getProductById()` reads that record type, so a kit or service item could never
be pulled.

**Per item** (`ImportNetSuiteDataAction::importProduct()`):

1. **New:** `PullNetSuiteProductAction` creates it in the warehouse of the **first** chosen location (the
   default one when none is chosen), and the refresh fills price, MAP, attributes and that location's
   quantity.
2. **Existing:** `PullNetSuiteProductPriceAction` refreshes price, MAP, attributes and the quantity of
   its first warehouse.
3. Either way, every chosen location's warehouse that does not carry the product gets a row at the same
   price and quantity 0 (`Concerns/ResolvesVariantWarehouse`, shared with the stock import). NetSuite
   prices an item, not a location.
4. The other locations' quantities come from `STOCK`. With **2 or more** locations chosen, the last chunk
   queues a `STOCK` import by itself; when it cannot (no stock saved search, or one already running) it
   says so in `summary`.

A failing item counts in `failed` and does not stop the rest. A soft-deleted product with a live variant
goes through the pull, which restores it.

## Customers

Each customer becomes a Guild **Organization** with its billing contact
(`SyncNetSuiteCustomerWithOrganizationAction`, the same action the invoice and sales-order imports use).
An existing customer is **not updated**: that action returns it without calling NetSuite, and its test
requires that. It counts as `skipped`.

Without a saved search it lists every active customer. The account tested has **40,064** (9 min just to
list); set `NET_SUITE_CUSTOMERS_SAVED_SEARCH_ID` to a sensible filter before offering it.

## Stock

`PullNetSuiteProductStockAction`: one advanced search per mapped warehouse (saved search + location join)
returning `locationQuantityAvailable`, writing the quantity of every SKU already in Kanvas. Runs whole in
one job; `summary` keeps the totals, not the per-SKU lists.

Needs a saved search: the input or `NET_SUITE_STOCK_SAVED_SEARCH_ID`. On the account tested **574** works
and **576** (the historical default of `kanvas:netsuite-sync-stock`) returns no rows.

## Saved searches

Resolution order (`ImportEntityEnum::resolveSavedSearchId()`):

1. `saved_search_id` from the input.
2. The **company** setting: `NET_SUITE_PRODUCTS_SAVED_SEARCH_ID`, `NET_SUITE_CUSTOMERS_SAVED_SEARCH_ID`,
   `NET_SUITE_STOCK_SAVED_SEARCH_ID`.
3. The same setting on the **app** (the NetSuite account belongs to the app).
4. None: products and customers use a basic search; stock is refused.

Ids belong to each NetSuite account; they do not carry over between tenants.

## Status and concurrency

- Stored per company and entity in the `NET_SUITE_IMPORT_STATUS_{ENTITY}` setting. Only one job writes it
  at a time (each chunk queues the next), so there are no concurrent writers.
- The run's id list lives in the cache (`netsuite-import-ids:{company}:{entity}`), not in the setting.
- A `QUEUED`/`RUNNING` import blocks another of the same entity. One that stops reporting for more than
  3 hours (`STALE_AFTER_HOURS`, a dead worker) stops blocking.
- `errors` keeps the first 50.
- The job has `tries = 1` and `timeout = 3600`; on failure `failed()` marks the status `FAILED` with the
  message.

## Measured duration

Real account, about 1,700 products, `NET_SUITE_MAX_CONCURRENT_REQUESTS` = 2:

| Step | Time |
|---|---|
| List one location's items (basic search with join) | ~80 s |
| List every active item | ~110 s |
| One product, new or existing, with rows in 2 locations | ~3 s |
| Stock | ~4 s per location |
| List 40,000 customers | ~9 min |

## Known limits

- **The price refresh uses saved search 574, hardcoded.** `PullNetSuiteProductPriceAction` calls
  `NetSuiteProductSearchService::searchProductByItemNumber()` without a saved search, and its default is
  `574`, which belongs to the original account. For any other tenant the refresh fails until that is
  parametrized (or `NET_SUITE_USE_LEGACY_PRODUCT_SEARCH` is used).
- **Quotes fall back to location 4 when the warehouse has no mapping** (`NetSuiteQuoteService.php`).
  Mapped warehouses never hit it, but the fallback is still specific to the original account.
- **Existing customers are not updated** on a resync.
- The item record (`get`) carries no per-location quantities on this account, so per-location quantity
  only comes from advanced searches (refresh and stock).
