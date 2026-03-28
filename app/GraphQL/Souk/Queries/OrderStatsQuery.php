<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Queries;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Orders\Actions\ExportOrderPaymentsAction;
use Kanvas\Souk\Orders\Actions\GetOrderCommissionStatsAction;
use Kanvas\Souk\Orders\Actions\GetOrderPaymentStatsAction;
use Kanvas\Souk\Orders\Actions\GetOrderStatsAction;
use Kanvas\Souk\Orders\DataTransferObject\CommissionStats;
use Kanvas\Users\Models\Users;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class OrderStatsQuery
{
    public function getOrderStats(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): array
    {
        $app = app(Apps::class);

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
        $providerCompanyIds = array_map('intval', $input['provider_company_id'] ?? []);
        $providers = $input['providers'] ?? [];
        $userEmail = $input['user_email'] ?? null;

        $orderStats = new GetOrderStatsAction(
            $app,
            $initialStates,
            $finalStates,
            $currentCountStates,
            $productTypeSlugs,
            $orderTypeNames,
            $productId,
            $providerCompanyIds,
            $providers,
            $userEmail
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
        $providerCompanyIds = array_map('intval', $input['provider_company_id'] ?? []);
        $userEmail = $input['user_email'] ?? null;

        $orderStats = new GetOrderPaymentStatsAction(
            $app,
            $paidStates,
            $variantId,
            $productTypeSlugs,
            $orderTypeNames,
            $providers,
            $productId,
            $providerCompanyIds,
            $userEmail
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

    public function commissionStats(mixed $rootValue, array $request): CommissionStats
    {
        $user = auth()->user();
        $app = app(Apps::class);

        $company = isset($request['company_id'])
            ? Companies::getByIdFromCompanyApp((int) $request['company_id'], $user->getCurrentCompany(), $app)
            : ($user->isAppOwner() ? null : $user->getCurrentCompany());

        return new GetOrderCommissionStatsAction(
            app: $app,
            company: $company,
            from: Carbon::parse($request['from']),
            to: Carbon::parse($request['to']),
            orderType: $request['order_type'] ?? null,
        )->execute();
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
            userEmail: $input['user_email'] ?? null,
        )->execute();
    }
}
