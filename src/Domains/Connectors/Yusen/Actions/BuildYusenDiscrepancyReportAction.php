<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Yusen\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Yusen\Contracts\InventoryQuantitySource;
use Kanvas\Connectors\Yusen\DataTransferObject\InventoryBalance;
use Kanvas\Connectors\Yusen\Enums\DiscrepancyTypeEnum;
use Kanvas\Connectors\Yusen\Services\YusenSettings;
use Kanvas\Connectors\Yusen\Sources\KanvasWarehouseQuantitySource;
use Kanvas\Connectors\Yusen\Sources\NetSuiteSavedSearchQuantitySource;
use Throwable;

/**
 * Diffs a Yusen balance against every system that has an opinion about the same stock.
 *
 * Writes nothing. Yusen's file is a 3PL physical count, not a stock feed — recording it as Kanvas
 * state belongs in the movement ledger (`docs/inventory-movement-ledger-plan.md`), where a 3PL
 * balance is a `cycle_count` batch with `external_source = wms-3pl`. Until that exists, putting
 * the count in a second warehouse would double every SKU's stock, because
 * `Variants::setTotalQuantity()` sums every warehouse row with no notion of which source it came
 * from — and that total is what the agent inventory tools quote to customers.
 *
 * Adding a system (Acumatica, QuickBooks, a second 3PL) is one `InventoryQuantitySource` and no
 * change here. Several run at once because they fail differently: Kanvas is free and catches drift
 * in what we believe we hold, NetSuite is the authoritative book count and catches Kanvas being
 * stale and wrong in the same direction as the ERP.
 */
class BuildYusenDiscrepancyReportAction
{
    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
        private readonly InventoryBalance $balance,
        /** @var array<int, InventoryQuantitySource>|null null builds the configured default set */
        private readonly ?array $sources = null,
    ) {
    }

    public function execute(): array
    {
        $settings = new YusenSettings($this->app, $this->company);
        $tolerance = $settings->quantityTolerance();
        $kanvas = new KanvasWarehouseQuantitySource($this->app, $this->company, $settings);
        $sent = $this->itemsInBalance();

        $rows = [];
        $errors = [];

        foreach ($this->sources ?? $this->defaultSources($settings, $kanvas) as $source) {
            try {
                $rows = array_merge($rows, $this->diff($source, $tolerance, $sent));
            } catch (Throwable $e) {
                // One source being down must not throw away the legs that already ran — a partial
                // report beats none, and the reason travels with it.
                $errors[$source->key()] = $e->getMessage();
                report($e);
            }
        }

        return $this->summarize($this->withVariantIds($rows, $kanvas), $errors, $kanvas->isConfigured());
    }

    /**
     * @return array<int, InventoryQuantitySource>
     */
    private function defaultSources(YusenSettings $settings, KanvasWarehouseQuantitySource $kanvas): array
    {
        $sources = [];

        if ($kanvas->isConfigured()) {
            $sources[] = $kanvas;
        }

        if ($settings->reconcileWithNetSuite()) {
            $sources[] = new NetSuiteSavedSearchQuantitySource($this->app, $this->company, $settings);
        }

        return $sources;
    }

    /**
     * Two-way diff between Yusen's file and one source. Every discrepancy class falls out of this
     * — there is no per-source branching.
     *
     * @param array<string, true> $sent
     * @return array<int, array<string, mixed>>
     */
    private function diff(InventoryQuantitySource $source, float $tolerance, array $sent): array
    {
        $quantities = $source->quantities();
        $key = $source->key();
        $rows = [];

        foreach ($this->balance->lines as $line) {
            if (! array_key_exists($line->item, $quantities)) {
                $rows[] = $this->row(
                    $line->item,
                    $line->warehouseCode,
                    $line->description,
                    $key,
                    DiscrepancyTypeEnum::missingFor($key),
                    $line->quantity,
                    null,
                );

                continue;
            }

            $sourceQuantity = $quantities[$line->item];

            if (abs($line->quantity - $sourceQuantity) > $tolerance) {
                $rows[] = $this->row(
                    $line->item,
                    $line->warehouseCode,
                    $line->description,
                    $key,
                    DiscrepancyTypeEnum::QUANTITY_MISMATCH,
                    $line->quantity,
                    $sourceQuantity,
                );
            }
        }

        // The other direction: stock the source still carries that Yusen's file never mentioned.
        foreach ($quantities as $item => $sourceQuantity) {
            $item = (string) $item;

            if ($sourceQuantity <= $tolerance || isset($sent[$item])) {
                continue;
            }

            $rows[] = $this->row(
                $item,
                null,
                $source->describe($item),
                $key,
                DiscrepancyTypeEnum::MISSING_IN_YUSEN,
                null,
                $sourceQuantity,
            );
        }

        return $rows;
    }

    /**
     * Stamps the Kanvas variant on each row, after the diff rather than during it, so the diff
     * stays source-agnostic and only rows that actually made the report cost a lookup.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function withVariantIds(array $rows, KanvasWarehouseQuantitySource $kanvas): array
    {
        foreach ($rows as $index => $row) {
            $rows[$index]['variant_id'] = $kanvas->variantId((string) $row['item']);
        }

        return $rows;
    }

    /**
     * Balance lines are keyed by "{item}|{warehouseCode}" because one item can sit in several of
     * Yusen's warehouses, so membership has to be collapsed to the item.
     *
     * @return array<string, true>
     */
    private function itemsInBalance(): array
    {
        $items = [];

        foreach ($this->balance->lines as $line) {
            $items[$line->item] = true;
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        string $item,
        ?string $warehouseCode,
        ?string $description,
        string $source,
        DiscrepancyTypeEnum $type,
        ?float $yusenQuantity,
        ?float $comparedQuantity,
    ): array {
        return [
            'item' => $item,
            'warehouse_code' => $warehouseCode,
            'description' => $description,
            'source' => $source,
            'type' => $type->value,
            'yusen_quantity' => $yusenQuantity,
            'compared_quantity' => $comparedQuantity,
            'difference' => $yusenQuantity !== null && $comparedQuantity !== null
                ? $yusenQuantity - $comparedQuantity
                : null,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, string> $errors
     */
    private function summarize(array $rows, array $errors, bool $kanvasConfigured): array
    {
        $bySource = [];
        $byType = [];

        foreach ($rows as $row) {
            $bySource[$row['source']] = ($bySource[$row['source']] ?? 0) + 1;
            $byType[$row['type']] = ($byType[$row['type']] ?? 0) + 1;
        }

        return [
            'external_id' => $this->balance->externalId,
            'generated_at' => $this->balance->generatedAt?->toIso8601String(),
            'total_records' => $this->balance->totalRecords,
            'total_items' => count($this->balance->lines),
            'total_quantity' => $this->balance->totalQuantity(),
            'multi_record_items' => $this->balance->multiRecordItems(),
            'total_discrepancies' => count($rows),
            'by_source' => $bySource,
            'by_type' => $byType,
            'source_errors' => $errors,
            'netsuite_error' => $errors['netsuite'] ?? null,
            'kanvas_warehouse_configured' => $kanvasConfigured,
            'rows' => $rows,
        ];
    }
}
