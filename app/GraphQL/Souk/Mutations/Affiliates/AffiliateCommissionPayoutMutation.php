<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Affiliates;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Affiliates\Actions\CreateAffiliateCommissionPayoutAction;
use Kanvas\Souk\Affiliates\Actions\UpdateAffiliateCommissionPayoutAction;
use Kanvas\Souk\Affiliates\DataTransferObject\AffiliateCommissionPayout as AffiliateCommissionPayoutData;
use Kanvas\Souk\Affiliates\Enums\AffiliatePayoutStatusEnum;
use Kanvas\Souk\Affiliates\Enums\PayoutMethodEnum;
use Kanvas\Souk\Affiliates\Models\Affiliate;
use Kanvas\Souk\Affiliates\Models\AffiliateCommissionPayout;
use Kanvas\Users\Models\Users;

class AffiliateCommissionPayoutMutation
{
    /**
     * @psalm-suppress MixedArrayAccess
     */
    public function create(mixed $rootValue, array $request): AffiliateCommissionPayout
    {
        /** @var Users $user */
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        /** @var array<string, mixed> $input */
        $input = $request['input'];

        /** @var Affiliate $affiliate */
        $affiliate = Affiliate::getByIdFromCompanyApp((int) $input['affiliate_id'], $company, $app);

        return new CreateAffiliateCommissionPayoutAction(
            new AffiliateCommissionPayoutData(
                app: $app,
                company: $company,
                user: $user,
                affiliate: $affiliate,
                period_start: $input['period_start'],
                period_end: $input['period_end'],
                total_conversions: (int) $input['total_conversions'],
                total_order_value: (float) $input['total_order_value'],
                gross_commission: (float) $input['gross_commission'],
                net_commission: (float) $input['net_commission'],
                fees: (float) ($input['fees'] ?? 0),
                adjustments: (float) ($input['adjustments'] ?? 0),
                payout_status: AffiliatePayoutStatusEnum::from($input['payout_status'] ?? 'pending_approval'),
                payout_method: isset($input['payout_method']) ? PayoutMethodEnum::from($input['payout_method']) : null,
                approval_notes: $input['approval_notes'] ?? null,
                configuration: $input['configuration'] ?? null,
            ),
        )->execute();
    }

    /**
     * @psalm-suppress MixedArrayAccess
     */
    public function update(mixed $rootValue, array $request): AffiliateCommissionPayout
    {
        /** @var Users $user */
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        /** @var array<string, mixed> $input */
        $input = $request['input'];

        /** @var AffiliateCommissionPayout $payout */
        $payout = AffiliateCommissionPayout::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new UpdateAffiliateCommissionPayoutAction(
            payout: $payout,
            payout_status: isset($input['payout_status']) ? AffiliatePayoutStatusEnum::from($input['payout_status']) : null,
            payout_method: isset($input['payout_method']) ? PayoutMethodEnum::from($input['payout_method']) : null,
            payout_reference: $input['payout_reference'] ?? null,
            approval_notes: $input['approval_notes'] ?? null,
            failure_reason: $input['failure_reason'] ?? null,
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        /** @var Users $user */
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        /** @var AffiliateCommissionPayout $payout */
        $payout = AffiliateCommissionPayout::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        $payout->delete();

        return true;
    }
}
