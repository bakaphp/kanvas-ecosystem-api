<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Movipass\Queries;

use App\GraphQL\Connector\Movipass\Builders\MechanicOrdersBuilder;
use App\GraphQL\Souk\Handlers\OrderStatusHandler;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Actions\ExportOrdersAction;
use Nuwave\Lighthouse\WhereConditions\SQLOperator;

class ExportMechanicOrdersQuery
{
    /**
     * Default column layout for the roadside assistance cases export.
     *
     * The assistance case is mirrored on both metadata.assistance_case and
     * metadata.data.assistance_case (see PrepareRoadsideAssistanceCaseAction),
     * so the primary path resolves for every current record. A `field_mapper`
     * argument overrides this default when the client needs custom columns.
     */
    private const DEFAULT_FIELD_MAPPER = [
        'ID Orden' => 'id',
        'Estado' => 'orderStatus.name',
        'Cliente' => 'user_email',
        'Tel. Cliente' => 'user_phone',
        'Servicio' => 'metadata.assistance_case.service',
        'Mecánico' => 'metadata.assistance_case.mechanic.name',
        'Tel. Mecánico' => 'metadata.assistance_case.mechanic.phone',
        'Proveedor' => 'metadata.assistance_case.mechanic.company_name',
        'Solicitado' => 'metadata.assistance_case.requested_at',
        'Despachado' => 'metadata.assistance_case.dispatched_at',
        'En sitio' => 'metadata.assistance_case.arrived_at',
        'Completado' => 'metadata.assistance_case.completed_at',
        'Calificación' => 'metadata.assistance_case.user_rating',
        'Creado' => 'created_at',
    ];

    public function export(mixed $rootValue, array $args): array
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $query = new MechanicOrdersBuilder()->build($rootValue, $args);
        $this->applyOrderStatus($query, $args);
        $this->applyWhereConditions($query, $args['where'] ?? []);
        $this->applyOrderBy($query, $args);

        if ($query->count() === 0) {
            return [
                'status' => 'warning',
                'download_url' => null,
                'file_name' => null,
                'message' => 'No roadside assistance cases found matching the specified criteria.',
            ];
        }

        $metadata = $args['metadata'] ?? [];
        $metadata['custom_title'] ??= 'REPORTE DE CASOS DE ASISTENCIA VIAL';

        return new ExportOrdersAction(
            app: $app,
            user: $user,
            orderData: $query,
            fieldMapper: $args['field_mapper'] ?? self::DEFAULT_FIELD_MAPPER,
            metadata: $metadata,
            params: $args['where'] ?? [],
            timezone: $args['timezone'] ?? $user->timezone ?? null,
            filename: 'roadside_assistance_cases',
        )->execute($args['format']);
    }

    private function applyOrderStatus(Builder $query, array $args): void
    {
        if (isset($args['orderStatus']) && is_array($args['orderStatus'])) {
            $handler = new OrderStatusHandler(new SQLOperator());
            $handler($query, $args['orderStatus'], null, 'and');
        }
    }

    private function applyWhereConditions(Builder $query, array $conditions): void
    {
        $operatorMap = [
            'EQ' => '=',
            'NEQ' => '!=',
            'GT' => '>',
            'GTE' => '>=',
            'LT' => '<',
            'LTE' => '<=',
            'LIKE' => 'LIKE',
        ];

        $apply = function (array $condition) use ($query, $operatorMap): void {
            if (! isset($condition['column'], $condition['value'])) {
                return;
            }

            $column = strtolower((string) $condition['column']);
            $operator = strtoupper($condition['operator'] ?? 'EQ');
            $value = $condition['value'];

            if ($operator === 'BETWEEN' && is_array($value) && count($value) >= 2) {
                $query->whereBetween($column, [$value[0], $value[1]]);
            } elseif (in_array($operator, ['IN', 'NOT_IN'], true)) {
                $query->{$operator === 'IN' ? 'whereIn' : 'whereNotIn'}($column, (array) $value);
            } elseif (array_key_exists($operator, $operatorMap)) {
                $query->where($column, $operatorMap[$operator], $value);
            }
        };

        $apply($conditions);

        foreach ($conditions['AND'] ?? [] as $andCondition) {
            if (is_array($andCondition)) {
                $apply($andCondition);
            }
        }
    }

    private function applyOrderBy(Builder $query, array $args): void
    {
        if (! isset($args['orderBy']) || ! is_array($args['orderBy'])) {
            $query->orderBy('created_at', 'DESC');

            return;
        }

        foreach ($args['orderBy'] as $order) {
            if (! is_array($order) || ! isset($order['column'])) {
                continue;
            }

            $query->orderBy(strtolower((string) $order['column']), $order['order'] ?? 'ASC');
        }
    }
}
