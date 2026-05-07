<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Affiliates;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Affiliates\Actions\CreateAffiliateProgramAction;
use Kanvas\Souk\Affiliates\Actions\UpdateAffiliateProgramAction;
use Kanvas\Souk\Affiliates\DataTransferObject\AffiliateProgram as AffiliateProgramData;
use Kanvas\Souk\Affiliates\Enums\CommissionTypeEnum;
use Kanvas\Souk\Affiliates\Enums\PayoutFrequencyEnum;
use Kanvas\Souk\Affiliates\Models\AffiliateProgram;
use Kanvas\Users\Models\Users;

class AffiliateProgramMutation
{
    /**
     * @psalm-suppress MixedArrayAccess
     */
    public function create(mixed $rootValue, array $request): AffiliateProgram
    {
        /** @var Users $user */
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        /** @var array<string, mixed> $input */
        $input = $request['input'];

        return new CreateAffiliateProgramAction(
            new AffiliateProgramData(
                app: $app,
                company: $company,
                user: $user,
                name: $input['name'],
                description: $input['description'] ?? null,
                is_active: (bool) ($input['is_active'] ?? true),
                accepts_new_affiliates: (bool) ($input['accepts_new_affiliates'] ?? true),
                require_approval: (bool) ($input['require_approval'] ?? true),
                default_commission_type: CommissionTypeEnum::from($input['default_commission_type'] ?? 'percentage'),
                default_commission_rate: (float) $input['default_commission_rate'],
                tier_based_commission: (bool) ($input['tier_based_commission'] ?? false),
                default_cookie_duration_days: (int) ($input['default_cookie_duration_days'] ?? 30),
                default_attribution_window_days: (int) ($input['default_attribution_window_days'] ?? 30),
                min_payout_amount: (float) ($input['min_payout_amount'] ?? 0),
                payout_frequency: PayoutFrequencyEnum::from($input['payout_frequency'] ?? 'monthly'),
                payout_methods_allowed: $input['payout_methods_allowed'] ?? null,
                restricted_countries: $input['restricted_countries'] ?? null,
                restricted_categories: $input['restricted_categories'] ?? null,
                restricted_products: $input['restricted_products'] ?? null,
                configuration: $input['configuration'] ?? null,
            ),
        )->execute();
    }

    /**
     * @psalm-suppress MixedArrayAccess
     */
    public function update(mixed $rootValue, array $request): AffiliateProgram
    {
        /** @var Users $user */
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        /** @var array<string, mixed> $input */
        $input = $request['input'];

        /** @var AffiliateProgram $program */
        $program = AffiliateProgram::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new UpdateAffiliateProgramAction(
            $program,
            new AffiliateProgramData(
                app: $app,
                company: $company,
                user: $user,
                name: $input['name'] ?? $program->name,
                description: $input['description'] ?? $program->description,
                is_active: (bool) ($input['is_active'] ?? $program->is_active),
                accepts_new_affiliates: (bool) ($input['accepts_new_affiliates'] ?? $program->accepts_new_affiliates),
                require_approval: (bool) ($input['require_approval'] ?? $program->require_approval),
                default_commission_type: CommissionTypeEnum::from($input['default_commission_type'] ?? $program->default_commission_type),
                default_commission_rate: (float) ($input['default_commission_rate'] ?? $program->default_commission_rate),
                tier_based_commission: (bool) ($input['tier_based_commission'] ?? $program->tier_based_commission),
                default_cookie_duration_days: (int) ($input['default_cookie_duration_days'] ?? $program->default_cookie_duration_days),
                default_attribution_window_days: (int) ($input['default_attribution_window_days'] ?? $program->default_attribution_window_days),
                min_payout_amount: (float) ($input['min_payout_amount'] ?? $program->min_payout_amount),
                payout_frequency: PayoutFrequencyEnum::from($input['payout_frequency'] ?? $program->payout_frequency),
                payout_methods_allowed: $input['payout_methods_allowed'] ?? $program->payout_methods_allowed,
                restricted_countries: $input['restricted_countries'] ?? $program->restricted_countries,
                restricted_categories: $input['restricted_categories'] ?? $program->restricted_categories,
                restricted_products: $input['restricted_products'] ?? $program->restricted_products,
                configuration: $input['configuration'] ?? $program->configuration,
            ),
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        /** @var Users $user */
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        /** @var AffiliateProgram $program */
        $program = AffiliateProgram::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        $program->delete();

        return true;
    }
}
