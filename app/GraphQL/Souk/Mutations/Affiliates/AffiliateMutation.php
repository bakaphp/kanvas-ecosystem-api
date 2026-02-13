<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Affiliates;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Affiliates\Actions\CreateAffiliateAction;
use Kanvas\Souk\Affiliates\Actions\UpdateAffiliateAction;
use Kanvas\Souk\Affiliates\DataTransferObject\Affiliate as AffiliateData;
use Kanvas\Souk\Affiliates\Enums\AffiliateStatusEnum;
use Kanvas\Souk\Affiliates\Enums\AffiliateTypeEnum;
use Kanvas\Souk\Affiliates\Enums\CommissionTypeEnum;
use Kanvas\Souk\Affiliates\Enums\PayoutFrequencyEnum;
use Kanvas\Souk\Affiliates\Enums\PayoutMethodEnum;
use Kanvas\Souk\Affiliates\Models\Affiliate;
use Kanvas\Souk\Affiliates\Models\AffiliateProgram;
use Kanvas\Souk\Affiliates\Models\AffiliateTier;
use Kanvas\Users\Models\Users;

class AffiliateMutation
{
    /**
     * @psalm-suppress MixedArrayAccess
     */
    public function create(mixed $rootValue, array $request): Affiliate
    {
        /** @var Users $user */
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        /** @var array<string, mixed> $input */
        $input = $request['input'];

        /** @var AffiliateProgram $program */
        $program = AffiliateProgram::getByIdFromCompanyApp((int) $input['affiliate_program_id'], $company, $app);

        /** @var AffiliateTier|null $tier */
        $tier = isset($input['affiliate_tier_id'])
            ? AffiliateTier::getByIdFromCompanyApp((int) $input['affiliate_tier_id'], $company, $app)
            : null;

        return new CreateAffiliateAction(
            new AffiliateData(
                app: $app,
                company: $company,
                user: $user,
                program: $program,
                name: $input['name'],
                email: $input['email'],
                commission_rate: (float) $input['commission_rate'],
                tier: $tier,
                users_id: isset($input['users_id']) ? (int) $input['users_id'] : null,
                phone: $input['phone'] ?? null,
                website_url: $input['website_url'] ?? null,
                social_profiles: $input['social_profiles'] ?? null,
                bio: $input['bio'] ?? null,
                profile_image_url: $input['profile_image_url'] ?? null,
                affiliate_type: AffiliateTypeEnum::from($input['affiliate_type'] ?? 'business'),
                status: AffiliateStatusEnum::from($input['status'] ?? 'pending'),
                commission_type: CommissionTypeEnum::from($input['commission_type'] ?? 'percentage'),
                min_payout_threshold: (float) ($input['min_payout_threshold'] ?? 0),
                payout_method: PayoutMethodEnum::from($input['payout_method'] ?? 'wallet'),
                payout_frequency: PayoutFrequencyEnum::from($input['payout_frequency'] ?? 'monthly'),
                unique_identifier: $input['unique_identifier'] ?? null,
                tracking_method: $input['tracking_method'] ?? 'utm_params',
                cookie_duration_days: (int) ($input['cookie_duration_days'] ?? 30),
                attribution_window_days: (int) ($input['attribution_window_days'] ?? 30),
                banking_details: $input['banking_details'] ?? null,
                paypal_details: $input['paypal_details'] ?? null,
                stripe_details: $input['stripe_details'] ?? null,
                configuration: $input['configuration'] ?? null,
            ),
        )->execute();
    }

    /**
     * @psalm-suppress MixedArrayAccess
     */
    public function update(mixed $rootValue, array $request): Affiliate
    {
        /** @var Users $user */
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        /** @var array<string, mixed> $input */
        $input = $request['input'];

        /** @var Affiliate $affiliate */
        $affiliate = Affiliate::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        /** @var AffiliateTier|null $tier */
        $tier = isset($input['affiliate_tier_id'])
            ? AffiliateTier::getByIdFromCompanyApp((int) $input['affiliate_tier_id'], $company, $app)
            : $affiliate->tier;

        return new UpdateAffiliateAction(
            $affiliate,
            new AffiliateData(
                app: $app,
                company: $company,
                user: $user,
                program: $affiliate->program,
                name: $input['name'] ?? $affiliate->name,
                email: $input['email'] ?? $affiliate->email,
                commission_rate: (float) ($input['commission_rate'] ?? $affiliate->commission_rate),
                tier: $tier,
                users_id: $affiliate->users_id,
                phone: $input['phone'] ?? $affiliate->phone,
                website_url: $input['website_url'] ?? $affiliate->website_url,
                social_profiles: $input['social_profiles'] ?? $affiliate->social_profiles,
                bio: $input['bio'] ?? $affiliate->bio,
                profile_image_url: $input['profile_image_url'] ?? $affiliate->profile_image_url,
                affiliate_type: AffiliateTypeEnum::from($input['affiliate_type'] ?? $affiliate->affiliate_type),
                status: AffiliateStatusEnum::from($input['status'] ?? $affiliate->status),
                commission_type: CommissionTypeEnum::from($input['commission_type'] ?? $affiliate->commission_type),
                min_payout_threshold: (float) ($input['min_payout_threshold'] ?? $affiliate->min_payout_threshold),
                payout_method: PayoutMethodEnum::from($input['payout_method'] ?? $affiliate->payout_method),
                payout_frequency: PayoutFrequencyEnum::from($input['payout_frequency'] ?? $affiliate->payout_frequency),
                unique_identifier: $input['unique_identifier'] ?? $affiliate->unique_identifier,
                tracking_method: $input['tracking_method'] ?? $affiliate->tracking_method,
                cookie_duration_days: (int) ($input['cookie_duration_days'] ?? $affiliate->cookie_duration_days),
                attribution_window_days: (int) ($input['attribution_window_days'] ?? $affiliate->attribution_window_days),
                banking_details: $input['banking_details'] ?? $affiliate->banking_details,
                paypal_details: $input['paypal_details'] ?? $affiliate->paypal_details,
                stripe_details: $input['stripe_details'] ?? $affiliate->stripe_details,
                configuration: $input['configuration'] ?? $affiliate->configuration,
            ),
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        /** @var Users $user */
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        /** @var Affiliate $affiliate */
        $affiliate = Affiliate::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        $affiliate->delete();

        return true;
    }
}
