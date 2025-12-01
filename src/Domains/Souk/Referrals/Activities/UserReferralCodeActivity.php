<?php

declare(strict_types=1);

namespace Kanvas\Souk\Referrals\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Referrals\Models\ReferralCode;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

class UserReferralCodeActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $user, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $defaultAppCompanyBranch = $app->get(AppSettingsEnums::GLOBAL_USER_REGISTRATION_ASSIGN_GLOBAL_COMPANY->getValue());

        $branch = CompaniesBranches::getById($defaultAppCompanyBranch);
        $company = $branch->company;

        return $this->executeIntegration(
            entity: $user,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function (Model $user, Apps $app, mixed $integrationCompany, array $additionalParams) {
                $referralCode = $user->get('user_referral_code') ?? null;

                if ($referralCode === null || empty($referralCode)) {
                    return $this->failWorkflow([
                        'message' => 'User does not have a referral code set',
                    ]);
                }

                $referralCode = ReferralCode::fromApp($app)
                    ->where('code', $referralCode)
                    ->where('is_active', true)
                    ->first();

                if (! $referralCode) {
                    $user->del('user_referral_code');

                    throw new ValidationException('Referral code doesn\'t exist');
                }

                if ($referralCode->isExpired()) {
                    $user->del('user_referral_code');

                    throw new ValidationException('Referral code has expired ');
                }

                return [
                    'message' => 'Referral code validation successful',
                    'code' => $referralCode->code,
                ];
            },
            company: $company,
        );
    }
}
