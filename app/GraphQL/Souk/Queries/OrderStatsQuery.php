<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Queries;

use GraphQL\Type\Definition\ResolveInfo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Actions\GetOrderPaymentStatsAction;
use Kanvas\Souk\Orders\Actions\GetOrderStatsAction;
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
        $date = $input['date'] ?? null;
        $startDate = $input['startDate'] ?? null;
        $endDate = $input['endDate'] ?? null;
        $timezone = $input['timezone'] ?? null;
        $baseDate = $input['baseDate'] ?? null;

        $orderStats = new GetOrderStatsAction(
            $app,
            $initialStates,
            $finalStates,
            $currentCountStates,
        )->execute(
            $date,
            $startDate,
            $endDate,
            $baseDate,
            $timezone
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
        $productTypeSlugs = $input['productTypeSlugs'] ?? [];
        $date = $input['date'] ?? null;
        $startDate = $input['startDate'] ?? null;
        $endDate = $input['endDate'] ?? null;
        $timezone = $input['timezone'] ?? 'UTC';
        $baseDate = $input['baseDate'] ?? null;

        $orderStats = new GetOrderPaymentStatsAction(
            $app,
            $paidStates,
            $variantId,
            $productTypeSlugs,
        )->execute(
            $date,
            $startDate,
            $endDate,
            $timezone
        );

        return $orderStats;
    }
}
