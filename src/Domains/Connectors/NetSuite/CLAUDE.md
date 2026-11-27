# NetSuite import — technical reference

How the on-demand NetSuite → Kanvas import works: what each entity does, how locations are mapped,
what has to be configured per tenant, and the known limits.

Connecting NetSuite (`createIntegrationCompany`) only stores credentials; it imports nothing. The user
triggers the import, and running it again is a resync.

## Pieces

The import runs on the generic integration layer (`src/Domains/Workflow/Integrations/Imports/CLAUDE.md`:
the `integrationImport` mutation, `integrationImportStatus` and `integrationImportOptionChoices`, run
status, chunking, the per-record try/catch and counting). NetSuite only supplies what to list and how to
import one record. There is no NetSuite-specific import mutation or status query.

| Piece | Where |
|---|---|
| What the integration offers (entities, labels, options) | `Handlers/NetSuiteHandler.php` (`importables()`) |
| Customers / products (chunked) | `Importers/NetSuiteCustomersImporter.php`, `Importers/NetSuiteProductsImporter.php` |
| Stock (one run) | `Importers/NetSuiteStockImporter.php` |
| Saved-search resolution | `Enums/ImportEntityEnum.php` (`resolveSavedSearchId()`) |
| Location → warehouse mapping | `Actions/ImportNetSuiteLocationsAction.php`, `Services/NetSuiteLocationWarehouseService.php` |
| The account's locations, location join filter | `Services/NetSuiteLocationService.php` |
| SOAP search pagination | `Client::searchAll()` |

Paths relative to `src/Domains/Connectors/NetSuite/`. NetSuite has no GraphQL of its own: everything goes
through the generic import (`src/Domains/Workflow/Integrations/Imports/CLAUDE.md`).

Entities and options (`importables()`):

| Entity | Options |
|---|---|
| `customers` | `saved_search_id` |
| `products` | `location_ids` (required, choices: the account's active locations), `saved_search_id` |
| `stock` | `saved_search_id`, `create_missing` |

The location picker comes from the generic `choices` of the `location_ids` option (`suggested` per
location, see below); there is no NetSuite-specific locations query.

## Per-company requirements

- NetSuite connected. Credentials live on the **app** (`NET_SUITE_ACCOUNT_CONFIG`): every company of an
  app shares one NetSuite account.
- Inventory set up (region and default warehouse). Without a default warehouse, mapping locations fails.
- At least one mapped location for `PRODUCTS` and `STOCK`. The importer checks it before queuing
  (`NetSuiteStockImporter::prepare()` via `NetSuiteLocationWarehouseService::assertMapped()`; products map
  the chosen locations themselves): with no location the price refresh has nothing
  to read from, so every product would land at price 0 and count as failed.
- A worker on the `imports` queue (present in all three `docker-compose` files).

## Locations

NetSuite keeps each item's quantity **per location**. Each imported location is mirrored by a Kanvas
warehouse through the warehouse's `NET_SUITE_LOCATION_ID` custom field; the price refresh
(`NetSuiteLocationWarehouseService::locationFor()`, which lets a value on the variant-warehouse row win
for data mapped the old way) and the stock import read it from there.

They are mapped while importing products (`ImportNetSuiteLocationsAction::ensureMapped()`, called by
`NetSuiteProductsImporter::prepare()`): the location the default warehouse already mirrors stays there;
on a company with none mapped, the **first one chosen** goes onto the default warehouse. The rest become
new warehouses named after the location. Nothing exposes changing the default location today;
`ImportNetSuiteLocationsAction::execute($defaultLocationId, $locationIds)` does it (support or tinker).

Rules:

- The default goes onto the **existing default warehouse**, never a new one: current products sit there
  and `VariantService` creates new ones there.
- A location is never mirrored by two warehouses: every warehouse's stock adds to the product total
  (`Variants::setTotalQuantity()`), so it would count twice.
- Only active locations of the account are accepted.
- Running it again never duplicates warehouses.
- It also writes `NET_SUITE_DEFAULT_WAREHOUSE` on the company (the default location's id): the fallback
  the price refresh uses for a variant in an unmapped warehouse.

`suggested` on a location choice is `type = warehouse` and "Make Inventory Available". It is only a
hint: on the account tested, "In Transit", "Other" and "Amazon" are flagged as available inventory, and
"Chicago" is a warehouse-typed location with no available inventory. The user decides.

## Products

Lists, then the generic job processes it in chunks of 100.

**Which items:**

| Input | Search |
|---|---|
| `saved_search_id` (or setting `NET_SUITE_PRODUCTS_SAVED_SEARCH_ID`) | That saved search. |
| `location_ids` (required), no saved search | Basic search of active inventory items joined to those locations. One record per item even when several locations carry it. |

Inventory items only: `getProductById()` reads that record type, so a kit or service item could never
be pulled.

**Per item** (`NetSuiteProductsImporter::importOne()`):

1. **New:** `PullNetSuiteProductAction` creates it in the warehouse of the **first** chosen location, and the refresh fills price, MAP, attributes and that location's
   quantity.
2. **Existing:** `PullNetSuiteProductPriceAction` refreshes price, MAP, attributes and the quantity of
   its first warehouse.
3. Either way, every chosen location's warehouse that does not carry the product gets a row at the same
   price and quantity 0 (`Kanvas\Inventory\Variants\Actions\EnsureVariantInWarehouseAction`, shared
   with the stock import and open to any connector with per-location stock). NetSuite
   prices an item, not a location.
4. The other locations' quantities come from `STOCK`. With **2 or more** locations chosen, `completed()`
   starts a `stock` import by itself (through `StartIntegrationImportAction`); when it cannot (no stock
   saved search, or one already running) the run's `summary` says so.

A failing item counts in `failed` (the generic job catches what `importOne()` throws) and does not stop
the rest. A soft-deleted product with a live variant
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
one job (`NetSuiteStockImporter::run()`); `summary` keeps the totals, not the per-SKU lists.

Needs a saved search: the input or `NET_SUITE_STOCK_SAVED_SEARCH_ID`. On the account tested **574** works
and **576** (the historical default of `kanvas:netsuite-sync-stock`) returns no rows.

## Saved searches

Resolution order (`ImportEntityEnum::resolveSavedSearchId()`, called from each importer's `prepare()`, so
the resolved id is what the run stores):

1. `saved_search_id` from the input.
2. The **company** setting: `NET_SUITE_PRODUCTS_SAVED_SEARCH_ID`, `NET_SUITE_CUSTOMERS_SAVED_SEARCH_ID`,
   `NET_SUITE_STOCK_SAVED_SEARCH_ID`.
3. The same setting on the **app** (the NetSuite account belongs to the app).
4. None: products and customers use a basic search; stock is refused.

Ids belong to each NetSuite account; they do not carry over between tenants.

## Status and concurrency

Run status, the one-import-at-a-time guard, stale-run handling, the error cap and the job's `tries` and
`timeout` belong to the generic layer: see `src/Domains/Workflow/Integrations/Imports/`.

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
- **Quotes do not read the warehouse mapping.** `NetSuiteQuoteService` reads the location off the
  variant-warehouse row only and falls back to location 4, which is specific to the original account;
  `SyncNetSuiteProductsAction` reads the row only too. Both should move to `locationFor()`.
- **Existing customers are not updated** on a resync.
- The item record (`get`) carries no per-location quantities on this account, so per-location quantity
  only comes from advanced searches (refresh and stock).
