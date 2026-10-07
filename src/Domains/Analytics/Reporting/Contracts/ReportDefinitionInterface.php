<?php

declare(strict_types=1);

namespace Kanvas\Analytics\Reporting\Contracts;

use Kanvas\Analytics\Reporting\Enums\ReportGrainEnum;

/**
 * A flat reporting table, declared by whoever owns the data.
 *
 * The domain holds the rules — DDL, query compilation, refresh, the agent tools — and a connector
 * supplies the definitions. Same split as the inbound-burst layer (`Social\Messages` + a
 * connector's BurstPolicy) and the Insurance providers.
 *
 * Definitions live in code, not in a DB row: they generate DDL, and DDL driven by a row someone
 * edited in production is how you lose a table.
 *
 * Scope note: this interface covers **schema** only. Refresh — the source query and the reverse
 * dependencies that invalidate rows — is deliberately not here yet; it lands with the refresh
 * service so definitions are not forced to stub methods nothing calls.
 */
interface ReportDefinitionInterface
{
    /**
     * Logical model name, snake_case. The physical table is `rpt_{model}_app{app_id}`.
     */
    public function model(): string;

    /**
     * Shown to operators and to the agent via `describe_report_model`.
     */
    public function label(): string;

    public function grain(): ReportGrainEnum;

    /**
     * The column carrying the row's identity — the PK of the flat table.
     */
    public function primaryKey(): string;

    /**
     * @return array<int, \Kanvas\Analytics\Reporting\DataTransferObject\ReportColumn>
     */
    public function columns(): array;
}
