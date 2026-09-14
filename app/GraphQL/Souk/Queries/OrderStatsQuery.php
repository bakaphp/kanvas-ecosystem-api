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
use Kanvas\Souk\Orders\Enums\OrderStatsExcludeModeEnum;
use Kanvas\Souk\Orders\Services\OrderProviderScopeService;
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
        $variantId = isset($input['variantId']) ? (int) $input['variantId'] : null;
        $date = $input['date'] ?? null;
        $startDate = $input['startDate'] ?? null;
        $endDate = $input['endDate'] ?? null;
        $timezone = $input['timezone'] ?? 'UTC';
        $baseDate = $input['baseDate'] ?? null;
        $groupBy = strtolower($input['groupBy'] ?? 'DAY');
        $providerCompanyIds = $this->resolveProviderCompanyIds($app, $input);
        $providers = $input['providers'] ?? [];
        $userEmail = $input['user_email'] ?? null;
        $excludeStates = $input['excludeStates'] ?? [];
        $excludeMode = OrderStatsExcludeModeEnum::from(strtolower($input['excludeMode'] ?? 'current'));

        $orderStats = new GetOrderStatsAction(
            app: $app,
            initialStates: $initialStates,
            finalStates: $finalStates,
            currentCountStates: $currentCountStates,
            productTypeSlugs: $productTypeSlugs,
            orderTypeNames: $orderTypeNames,
            productId: $productId,
            variantId: $variantId,
            providerCompanyIds: $providerCompanyIds,
            providers: $providers,
            userEmail: $userEmail,
            excludeStates: $excludeStates,
            excludeMode: $excludeMode,
        )->execute(
            date: $date,
            startDate: $startDate,
            endDate: $endDate,
            baseDate: $baseDate,
            timezone: $timezone,
            groupBy: $groupBy,
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
        $providerCompanyIds = $this->resolveProviderCompanyIds($app, $input);
        $userEmail = $input['user_email'] ?? null;
        $metadataFilter = $input['metadata'] ?? null;
        $reference = $input['reference'] ?? null;
        $orderNumber = $input['orderNumber'] ?? null;

        $orderStats = new GetOrderPaymentStatsAction(
            $app,
            $paidStates,
            $variantId,
            $productTypeSlugs,
            $orderTypeNames,
            $providers,
            $productId,
            $providerCompanyIds,
            $userEmail,
            $reference,
            $orderNumber,
            $metadataFilter,
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
        $input = $request['input'];

        $providerCompanyIds = $this->resolveProviderCompanyIds($app, $input);

        $company = isset($input['company_id'])
            ? Companies::getByIdFromCompanyApp((int) $input['company_id'], $user->getCurrentCompany(), $app)
            : (! empty($providerCompanyIds) || $user->isAppOwner() ? null : $user->getCurrentCompany());

        return new GetOrderCommissionStatsAction(
            app: $app,
            company: $company,
            from: Carbon::parse($input['from']),
            to: Carbon::parse($input['to']),
            orderType: $input['order_type'] ?? null,
            providerCompanyIds: $providerCompanyIds,
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
            providerCompanyIds: $this->resolveProviderCompanyIds($app, $input),
            metadata: $args['metadata'] ?? [],
            includeSummary: $input['includeSummary'] ?? true,
        )->execute();
    }

    /**
     * @return array<int, int>
     */
    private function resolveProviderCompanyIds(Apps $app, array $input): array
    {
        /** @var Users $user */
        $user = auth()->user();

        return OrderProviderScopeService::resolve(
            app: $app,
            company: $user->getCurrentCompany(),
            isAppOwner: $user->isAppOwner(),
            requested: $input['provider_company_id'] ?? [],
        );
    }
}
