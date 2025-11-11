<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Builders\Referral;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Referrals\Models\ReferralRedemption;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class ReferralRedemptionBuilder
{
    /**
     * Build query for referral code usages with proper filtering and scoping.
     */
    public function getReferralCodeUsagesBuilder(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $app = app(Apps::class);
        $user = auth()->user();
        $code = $args['code'] ?? null;

        if ($code === null) {
            throw new ValidationException('Referral code is required');
        }

        // Start with fresh query to apply multi-tenant scoping
        $query = ReferralRedemption::fromApp($app)
            ->where('status', 'completed')
            ->with(['referrer', 'referee', 'referralCode'])
            ->whereHas('referralCode', function (Builder $q) use ($code, $user) {
                $q->where('code', $code);
                $q->where('users_id', $user->id);
            });

        // Order by created_at descending
        $query->orderBy('created_at', 'desc');

        return $query;
    }
}
