<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Referral;

use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Referrals\Models\ReferralCode;

class ReferralCodeMutation
{
    /**
     * Update a referral code.
     * Can modify code, is_active status, or other properties.
     * Admin can specify user_id to validate code ownership.
     * Protected by @guardByAppKey directive.
     */
    public function update(mixed $root, array $args): ReferralCode
    {
        $app = app(Apps::class);

        $referralCode = ReferralCode::fromApp($app)->find($args['id']);
        if (! $referralCode) {
            throw new ValidationException('Referral code not found');
        }

        // Extract and validate user_id if provided
        $userId = $args['user_id'] ?? null;
        if ($userId && (int) $referralCode->users_id !== (int) $userId) {
            throw new ValidationException('Referral code does not belong to the specified user');
        }

        // Build update data from input (excluding user_id since it's just for validation)
        $updateData = [];

        if (isset($args['code'])) {
            $updateData['code'] = $args['code'];
        }
        if (isset($args['is_active'])) {
            $updateData['is_active'] = $args['is_active'];
        }

        if (! empty($updateData)) {
            $referralCode->update($updateData);
        }

        return $referralCode;
    }
}
