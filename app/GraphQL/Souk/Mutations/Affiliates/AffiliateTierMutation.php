<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Affiliates;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Affiliates\Actions\CreateAffiliateTierAction;
use Kanvas\Souk\Affiliates\Actions\UpdateAffiliateTierAction;
use Kanvas\Souk\Affiliates\DataTransferObject\AffiliateTier as AffiliateTierData;
use Kanvas\Souk\Affiliates\Models\AffiliateProgram;
use Kanvas\Souk\Affiliates\Models\AffiliateTier;
use Kanvas\Users\Models\Users;

class AffiliateTierMutation
{
    /**
     * @psalm-suppress MixedArrayAccess
     */
    public function create(mixed $rootValue, array $request): AffiliateTier
    {
        /** @var Users $user */
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        /** @var array<string, mixed> $input */
        $input = $request['input'];

        /** @var AffiliateProgram $program */
        $program = AffiliateProgram::getByIdFromCompanyApp((int) $input['affiliate_program_id'], $company, $app);

        return new CreateAffiliateTierAction(
            new AffiliateTierData(
                app: $app,
                company: $company,
                user: $user,
                program: $program,
                name: $input['name'],
                level: (int) $input['level'],
                base_commission_rate: (float) $input['base_commission_rate'],
                description: $input['description'] ?? null,
                weight: (float) ($input['weight'] ?? 0),
                min_referrals: (int) ($input['min_referrals'] ?? 0),
                min_monthly_sales: isset($input['min_monthly_sales']) ? (float) $input['min_monthly_sales'] : null,
                min_conversion_rate: isset($input['min_conversion_rate']) ? (float) $input['min_conversion_rate'] : null,
                min_days_active: (int) ($input['min_days_active'] ?? 0),
                commission_multiplier: (float) ($input['commission_multiplier'] ?? 1.00),
                bonus_commission_rate: isset($input['bonus_commission_rate']) ? (float) $input['bonus_commission_rate'] : null,
                early_payout_eligibility: (bool) ($input['early_payout_eligibility'] ?? false),
                marketing_material_access: (bool) ($input['marketing_material_access'] ?? false),
                dedicated_manager: (bool) ($input['dedicated_manager'] ?? false),
                priority_support: (bool) ($input['priority_support'] ?? false),
                advanced_reporting: (bool) ($input['advanced_reporting'] ?? false),
                benefits: $input['benefits'] ?? null,
                is_active: (bool) ($input['is_active'] ?? true),
            ),
        )->execute();
    }

    /**
     * @psalm-suppress MixedArrayAccess
     */
    public function update(mixed $rootValue, array $request): AffiliateTier
    {
        /** @var Users $user */
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        /** @var array<string, mixed> $input */
        $input = $request['input'];

        /** @var AffiliateTier $tier */
        $tier = AffiliateTier::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        /** @var AffiliateProgram $program */
        $program = $tier->program;

        return new UpdateAffiliateTierAction(
            $tier,
            new AffiliateTierData(
                app: $app,
                company: $company,
                user: $user,
                program: $program,
                name: $input['name'] ?? $tier->name,
                level: (int) ($input['level'] ?? $tier->level),
                base_commission_rate: (float) ($input['base_commission_rate'] ?? $tier->base_commission_rate),
                description: $input['description'] ?? $tier->description,
                weight: (float) ($input['weight'] ?? $tier->weight),
                min_referrals: (int) ($input['min_referrals'] ?? $tier->min_referrals),
                min_monthly_sales: isset($input['min_monthly_sales']) ? (float) $input['min_monthly_sales'] : $tier->min_monthly_sales,
                min_conversion_rate: isset($input['min_conversion_rate']) ? (float) $input['min_conversion_rate'] : $tier->min_conversion_rate,
                min_days_active: (int) ($input['min_days_active'] ?? $tier->min_days_active),
                commission_multiplier: (float) ($input['commission_multiplier'] ?? $tier->commission_multiplier),
                bonus_commission_rate: isset($input['bonus_commission_rate']) ? (float) $input['bonus_commission_rate'] : $tier->bonus_commission_rate,
                early_payout_eligibility: (bool) ($input['early_payout_eligibility'] ?? $tier->early_payout_eligibility),
                marketing_material_access: (bool) ($input['marketing_material_access'] ?? $tier->marketing_material_access),
                dedicated_manager: (bool) ($input['dedicated_manager'] ?? $tier->dedicated_manager),
                priority_support: (bool) ($input['priority_support'] ?? $tier->priority_support),
                advanced_reporting: (bool) ($input['advanced_reporting'] ?? $tier->advanced_reporting),
                benefits: $input['benefits'] ?? $tier->benefits,
                is_active: (bool) ($input['is_active'] ?? $tier->is_active),
            ),
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        /** @var Users $user */
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        /** @var AffiliateTier $tier */
        $tier = AffiliateTier::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        $tier->delete();

        return true;
    }
}
