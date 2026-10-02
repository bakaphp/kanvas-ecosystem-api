<?php

declare(strict_types=1);

namespace App\GraphQL\Analytics\Queries;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Analytics\Reporting\Contracts\ReportDefinitionInterface;
use Kanvas\Analytics\Reporting\DataTransferObject\AggregateRequest;
use Kanvas\Analytics\Reporting\DataTransferObject\ReportColumn;
use Kanvas\Analytics\Reporting\DataTransferObject\ReportFilter;
use Kanvas\Analytics\Reporting\Services\ReportQueryService;
use Kanvas\Analytics\Reporting\Support\ReportRegistry;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;

/**
 * GraphQL face of the flat reporting tables.
 *
 * Tenancy is not handled here on purpose: the registry only returns models whose connector is
 * enabled for the app, the table name comes from the definition, and the service adds the company
 * predicate before any caller filter. This class only translates arguments.
 */
class ReportQuery
{
    use ResolvesActingContext;

    /**
     * @return list<array<string, mixed>>
     */
    public function models(mixed $rootValue, array $request): array
    {
        $definitions = new ReportRegistry()->for($this->actingContext()->app);

        return array_values(array_map(fn (ReportDefinitionInterface $definition) => [
            'model' => $definition->model(),
            'label' => $definition->label(),
            'grain' => $definition->grain()->value,
            'primary_key' => $definition->primaryKey(),
            'columns' => array_map(fn (ReportColumn $column) => [
                'name' => $column->name,
                'type' => $column->type,
                'label' => $column->label ?? $column->name,
                'indexed' => $column->indexed,
                'multi_valued' => $column->multiValued,
            ], $definition->columns()),
        ], $definitions));
    }

    /**
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    public function rows(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();
        /** @var Companies $company */
        $company = $ctx->company;
        $definition = new ReportRegistry()->find($ctx->app, (string) $request['model']);
        $filters = $this->filters($request['filters'] ?? []);

        $first = (int) ($request['first'] ?? 50);
        $page = (int) ($request['page'] ?? 1);

        if ($first < 1 || $page < 1) {
            throw new ValidationException('first and page must be 1 or greater.');
        }

        $first = min($first, ReportQueryService::MAX_LIMIT);
        $service = new ReportQueryService();

        return [
            'total' => $service->count($definition, $ctx->app, $company, $filters)['rows'],
            'rows' => $service->search(
                $definition,
                $ctx->app,
                $company,
                $filters,
                select: $request['select'] ?? null,
                limit: $first,
                offset: ($page - 1) * $first,
                orderBy: $request['order_by'] ?? null,
                descending: (bool) ($request['descending'] ?? false),
            ),
        ];
    }

    /**
     * @return array{columns: list<string>, rows: list<array<string, mixed>>}
     */
    public function aggregate(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();
        /** @var Companies $company */
        $company = $ctx->company;
        $definition = new ReportRegistry()->find($ctx->app, (string) $request['model']);

        $aggregateRequest = AggregateRequest::fromInput(
            aggregates: $request['aggregates'],
            groupBy: $request['group_by'] ?? [],
            orderBy: $request['order_by'] ?? null,
            descending: (bool) ($request['descending'] ?? true),
            limit: (int) ($request['limit'] ?? 50),
        );

        $rows = new ReportQueryService()->aggregate(
            $definition,
            $ctx->app,
            $company,
            $aggregateRequest,
            $this->filters($request['filters'] ?? []),
        );

        return [
            'columns' => [
                ...array_map(ReportQueryService::groupingKey(...), $aggregateRequest->groupBy),
                ...array_keys($aggregateRequest->aggregates),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @param list<array<string, mixed>> $input
     *
     * @return list<ReportFilter>
     */
    protected function filters(array $input): array
    {
        return array_map(fn (array $filter) => ReportFilter::fromArray($filter), $input);
    }
}
