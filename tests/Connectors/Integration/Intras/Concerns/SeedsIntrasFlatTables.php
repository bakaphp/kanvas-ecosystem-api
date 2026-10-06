<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras\Concerns;

use Kanvas\Analytics\Reporting\Contracts\ReportDefinitionInterface;
use Kanvas\Analytics\Reporting\Services\ReportSchemaService;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Intras\Enums\ConfigurationEnum;

/**
 * Rows written straight into the flat reporting tables, and the connector switched on so the
 * registry exposes them.
 *
 * The reporting connection is in no test's transaction list, so every row is removed by its own
 * key in `clearSeededFlatRows()`. Enablement is an app setting in shared Redis, which is why the
 * classes using this run in the `serial` group: a sibling worker's teardown would switch the
 * connector off mid-test.
 */
trait SeedsIntrasFlatTables
{
    /** @var array<string, array{definition: ReportDefinitionInterface, keys: list<int>}> */
    private array $seededFlatRows = [];

    protected function enableIntrasReporting(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::INTRAS_DB_HOST->value, '127.0.0.1');
        $app->set(ConfigurationEnum::INTRAS_DB_DATABASE->value, 'intras');
    }

    protected function disableIntrasReporting(): void
    {
        $app = app(Apps::class);
        $app->del(ConfigurationEnum::INTRAS_DB_HOST->value);
        $app->del(ConfigurationEnum::INTRAS_DB_DATABASE->value);
    }

    protected function flatCompanyId(): int
    {
        return (int) static::$cachedUser->getCurrentCompany()->getId();
    }

    /**
     * @param list<array<string, mixed>> $rows each carries the definition's primary key;
     *                                         `companies_id` defaults to the logged-in user's company
     */
    protected function seedFlatRows(ReportDefinitionInterface $definition, array $rows): void
    {
        $schema = new ReportSchemaService();
        $schema->sync($definition, app(Apps::class)->getId());
        $table = $schema->connection()->table($schema->tableFor($definition, app(Apps::class)->getId()));
        $key = $definition->primaryKey();
        $keys = array_map(fn (array $row) => (int) $row[$key], $rows);

        // A run that died before teardown leaves its rows behind; clear them so the insert can't collide.
        (clone $table)->whereIn($key, $keys)->delete();

        foreach ($rows as $row) {
            (clone $table)->insert([
                'companies_id' => $this->flatCompanyId(),
                'refreshed_at' => date('Y-m-d H:i:s'),
                ...$row,
            ]);
        }

        $this->seededFlatRows[$definition->model()] ??= ['definition' => $definition, 'keys' => []];
        $this->seededFlatRows[$definition->model()]['keys'] = [
            ...$this->seededFlatRows[$definition->model()]['keys'],
            ...$keys,
        ];
    }

    protected function clearSeededFlatRows(): void
    {
        $schema = new ReportSchemaService();

        foreach ($this->seededFlatRows as ['definition' => $definition, 'keys' => $keys]) {
            $schema->connection()
                ->table($schema->tableFor($definition, app(Apps::class)->getId()))
                ->whereIn($definition->primaryKey(), $keys)
                ->delete();
        }

        $this->seededFlatRows = [];
    }
}
