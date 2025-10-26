<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Builders\Referral;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Referrals\Models\ReferralRedemption;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class ReferralRedemptionBuilder
{
    /**
     * Build query for referral code usages with proper filtering and scoping.
     */
    public function getReferralCodeUsagesBuilder(
        Builder $builder,
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $app = app(Apps::class);
        $code = $args['code'] ?? null;

        // Start with fresh query to apply multi-tenant scoping
        $query = ReferralRedemption::fromApp($app)
            ->where('status', 'completed')
            ->with(['referrer', 'referee', 'referralCode']);

        // Filter by referral code if provided
        if ($code) {
            $query->whereHas('referralCode', function (Builder $q) use ($code) {
                $q->where('code', $code);
            });
        }

        // Order by created_at descending
        $query->orderBy('created_at', 'desc');

        return $query;
    }
}
