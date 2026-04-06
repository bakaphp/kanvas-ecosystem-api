<?php

declare(strict_types=1);

namespace Kanvas\Souk\Referrals\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Souk\Loyalty\Actions\AssignLoyaltyProgramAction;
use Kanvas\Souk\Referrals\Actions\GenerateReferralCodeAction;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

class ProcessUserSignupLoyaltyActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public $tries = 3;

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
            integrationOperation: function (Users $user, Apps $app) {
                // Step 1: Assign loyalty program to user
                $assignLoyaltyProgram = new AssignLoyaltyProgramAction($user, $app);
                $membership = $assignLoyaltyProgram->execute();

                // If no membership was created, user is not eligible for loyalty program
                if (! $membership) {
                    return $this->failWorkflow([
                        'result' => false,
                        'message' => 'No eligible loyalty program found , or tier configure incorrectly',
                        'user_id' => $user->getId(),
                    ]);
                }

                // Step 2: Generate referral code for the user
                $loyaltyProgram = $membership->program;

                // Only generate referral code if the program has referrals enabled
                $referralCode = null;
                if ($loyaltyProgram->referral_enabled) {
                    /** @var array<string, mixed> $referralConfig */
                    $referralConfig = is_array($loyaltyProgram->referral_config) ? $loyaltyProgram->referral_config : [];

                    $generateReferralCode = new GenerateReferralCodeAction(
                        user: $user,
                        loyaltyProgram: $loyaltyProgram,
                        app: $app,
                        referrerReward: isset($referralConfig['referrer_bonus']) ? (int) $referralConfig['referrer_bonus'] : 0,
                        refereeReward: isset($referralConfig['referee_bonus']) ? (int) $referralConfig['referee_bonus'] : 0,
                        refereeDiscount: isset($referralConfig['referee_discount']) ? (float) $referralConfig['referee_discount'] : 0.00
                    );

                    $referralCode = $generateReferralCode->execute();
                }

                return [
                    'result' => true,
                    'message' => 'User assigned to loyalty program' . ($referralCode ? ' and referral code generated' : ''),
                    'user_id' => $user->getId(),
                    'membership_id' => $membership->getId(),
                    'loyalty_program_id' => $membership->loyalty_programs_id,
                    'loyalty_tier_id' => $membership->loyalty_tiers_id,
                    'tier_name' => $membership->tier->name ?? null,
                    'referral_code' => $referralCode?->code,
                    'referral_code_id' => $referralCode?->getId(),
                ];
            },
            company: $company,
        );
    }
}
