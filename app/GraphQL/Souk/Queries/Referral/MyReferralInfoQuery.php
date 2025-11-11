<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Queries\Referral;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Referrals\Models\ReferralCode;
use Kanvas\Souk\Referrals\Models\ReferralRedemption;

class MyReferralInfoQuery
{
    /**
     * Get user's referral info: code, points earned, and list of referred users.
     * Admins can pass user_id to view another user's info.
     */
    public function handle(mixed $root, array $args): ?array
    {
        $app = app(Apps::class);
        $currentUser = auth()->user();

        if (! $currentUser) {
            return null;
        }

        // Determine which user's data to fetch
        $targetUserId = $args['user_id'] ?? null;
        $isAdmin = $currentUser->is_admin || $currentUser->is_superuser;

        if ($targetUserId) {
            // If user_id is provided, only admins can access
            if (! $isAdmin) {
                throw new \Exception('Unauthorized: Only admins can view other users\' referral info');
            }
            $userId = $targetUserId;
        } else {
            // Otherwise, use the current user's ID
            $userId = $currentUser->id;
        }

        // Get user's active referral code
        $referralCode = ReferralCode::fromApp($app)
            ->where('users_id', $userId)
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->first();

        if (! $referralCode) {
            return null;
        }

        // Get all successful redemptions for this referral code
        $redemptions = ReferralRedemption::fromApp($app)
            ->where('referral_codes_id', $referralCode->id)
            ->where('status', 'completed')
            ->with(['referee'])
            ->get();

        $totalPoints = (int) $redemptions->sum('referrer_points_awarded');
        $totalReferrals = $redemptions->count();

        // Build referred users list
        $referredUsers = $redemptions->map(function ($redemption) {
            $referee = $redemption->referee;
            $name = $referee->name ?? trim(($referee->firstname ?? '') . ' ' . ($referee->lastname ?? ''));

            return [
                'id' => $referee->id,
                'user_id' => $referee->id,
                'name' => $name,
                'email' => $referee->email,
                'points_earned' => $redemption->referrer_points_awarded,
                'discount_applied' => $redemption->referee_discount_amount,
                'referred_at' => $redemption->created_at,
            ];
        })->values()->toArray();

        return [
            'referral_code' => $referralCode->code,
            'total_points_earned' => $totalPoints,
            'total_referrals' => $totalReferrals,
            'referred_users' => $referredUsers,
        ];
    }
}
