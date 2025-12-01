<?php

declare(strict_types=1);

namespace Kanvas\Souk\Referrals\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Referrals\Models\ReferralCode;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

class UserReferralCodeActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $user, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $referralCode = $user->get('user_referral_code') ?? null;

        if (! $referralCode) {
            return $this->failWorkflow([
                'message' => 'User does not have a referral code set',
            ]);
        }

        $referralCode = ReferralCode::fromApp($app)
            ->where('code', $referralCode)
            ->where('is_active', true)
            ->first();

        if (! $referralCode) {
            throw new ValidationException('Referral code doesn\'t exist');
        }

        if ($referralCode->isExpired()) {
            throw new ValidationException('Referral code has expired ');
        }

        return [
            'message' => 'Referral code validation successful',
            'code' => $referralCode->code,
        ];
    }
}
