<?php

declare(strict_types=1);

namespace Kanvas\Analytics\Reporting\Concerns;

use Illuminate\Support\Facades\DB;
use Kanvas\Companies\Models\Companies;

/**
 * Read an already-flattened report table from a definition that depends on it.
 *
 * A definition that denormalises from a parent grain — `cotizacion` reading `empresa`,
 * `evaluacion` reading `ejecutivo` — must look the parent row up by its key. Six definitions
 * grew their own copy of that lookup and **not one of them filtered `companies_id`**, so a row
 * could be resolved out of another tenant's data purely because entity ids are globally unique.
 *
 * That was not theoretical: `empresa_plan` yielded all 42 plan-holding organizations to every
 * company's rebuild, and because its synthetic primary key is derived from the organization id
 * alone, each company's run overwrote the previous one's rows. Four rebuilds later the table held
 * one company's stamp on another company's plans.
 *
 * Every lookup goes through here, and the tenant predicate is not optional.
 */
trait ReadsFlatReportTables
{
    /**
     * @param array<int, int> $ids
     *
     * @return array<int, object> keyed by $keyColumn
     */
    protected function flatRows(
        string $model,
        string $keyColumn,
        array $ids,
        Companies $company
    ): array {
        if ($ids === []) {
            return [];
        }

        $table = sprintf('rpt_%s_app%d', $model, $this->appId);
        $connection = DB::connection('reporting');

        if (! $connection->getSchemaBuilder()->hasTable($table)) {
            return [];
        }

        $map = [];

        $rows = $connection->table($table)
            ->where('companies_id', $company->getId())
            ->whereIn($keyColumn, $ids)
            ->get();

        foreach ($rows as $row) {
            $map[(int) $row->{$keyColumn}] = $row;
        }

        return $map;
    }
}
