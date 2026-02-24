<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Queries;

use GraphQL\Type\Definition\ResolveInfo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Actions\ExportOrderPaymentsAction;
use Kanvas\Souk\Orders\Actions\GetOrderPaymentStatsAction;
use Kanvas\Souk\Orders\Actions\GetOrderStatsAction;
use Kanvas\Users\Models\Users;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class OrderStatsQuery
{
    public function getOrderStats(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): array
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $input = $args['input'];
        $initialStates = $input['initialStates'] ?? [];
        $finalStates = $input['finalStates'] ?? [];
        $currentCountStates = $input['currentCountStates'] ?? [];
        $productTypeSlugs = $input['productTypeSlugs'] ?? [];
        $orderTypeNames = $input['orderTypeNames'] ?? [];
        $productId = isset($input['productId']) ? (int) $input['productId'] : null;
        $date = $input['date'] ?? null;
        $startDate = $input['startDate'] ?? null;
        $endDate = $input['endDate'] ?? null;
        $timezone = $input['timezone'] ?? null;
        $baseDate = $input['baseDate'] ?? null;
        $groupBy = strtolower($input['groupBy'] ?? 'DAY');

        $orderStats = new GetOrderStatsAction(
            $app,
            $initialStates,
            $finalStates,
            $currentCountStates,
            $productTypeSlugs,
            $orderTypeNames,
            $productId
        )->execute(
            $date,
            $startDate,
            $endDate,
            $baseDate,
            $timezone,
            $groupBy
        );

        return $orderStats;
    }

    public function getPaymentStats(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): array
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $input = $args['input'];
        $paidStates = $input['paidStates'] ?? ['paid'];
        $variantId = $input['variantId'] ?? null;
        $productId = isset($input['productId']) ? (int) $input['productId'] : null;
        $productTypeSlugs = $input['productTypeSlugs'] ?? [];
        $orderTypeNames = $input['orderTypeNames'] ?? [];
        $providers = $input['providers'] ?? [];
        $date = $input['date'] ?? null;
        $startDate = $input['startDate'] ?? null;
        $endDate = $input['endDate'] ?? null;
        $timezone = $input['timezone'] ?? 'UTC';
        $baseDate = $input['baseDate'] ?? null;
        $groupPeriods    = $input['groupPeriods'] ?? null;
        $periodBreakdown = $input['periodBreakdown'] ?? 'MONTH';

        $orderStats = new GetOrderPaymentStatsAction(
            $app,
            $paidStates,
            $variantId,
            $productTypeSlugs,
            $orderTypeNames,
            $providers,
            $productId
        )->execute(
            $date,
            $startDate,
            $endDate,
            $timezone,
            $groupPeriods,
            $periodBreakdown,
        );

        return $orderStats;
    }

    public function exportPayments(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): array
    {
        $app   = app(Apps::class);
        $input = $args['input'];

        /** @var Users $user */
        $user = auth()->user();

        return new ExportOrderPaymentsAction(
            app: $app,
            user: $user,
            paidStates: $input['paidStates'] ?? ['paid'],
            orderTypeNames: $input['orderTypeNames'] ?? [],
            timezone: $input['timezone'] ?? 'UTC',
            startDate: $input['startDate'] ?? null,
            endDate: $input['endDate'] ?? null,
            fieldMapper: isset($input['fieldMapper']) ? (array) $input['fieldMapper'] : null,
            language: $input['language'] ?? 'en',
        )->execute();
    }
}
