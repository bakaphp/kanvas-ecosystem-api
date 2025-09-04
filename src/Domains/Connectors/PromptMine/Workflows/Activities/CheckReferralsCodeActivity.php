<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Souk\Discounts\Models\Discount;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

class CheckReferralsCodeActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public $tries = 3;

    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);
        $defaultAppCompanyBranch = $app->get(AppSettingsEnums::GLOBAL_USER_REGISTRATION_ASSIGN_GLOBAL_COMPANY->getValue());

        //Check if user has been created in the last 24 hours and if it has a referral code attached, the custom field `referral_code`
        if (! $entity->created_at > now()->subDay() && is_null($entity->get('referral_code'))) {
            return [
                'result' => false,
                'message' => 'User has not referral code or has been created ',
            ];
        }

        try {
            $branch = CompaniesBranches::getById($defaultAppCompanyBranch);
            $company = $branch->company;
        } catch (ModelNotFoundException $e) {
            $company = $entity->company;
        }

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::PROMPT_MINE,
            integrationOperation: function ($entity, $app, $integrationCompany, $additionalParams) use ($params) {
                $discountCode = Discount::fromApp($app)
                    ->where('code', Str::upper($entity->get('referral_code')))
                    ->where('is_active', true)
                    ->where('is_deleted', false)
                    ->first();

                if (! $discountCode) {
                    return [
                        'result' => false,
                        'users_id' => $entity->getId(),
                        'message' => "Invalid referral code for user {$entity->getId()}",
                    ];
                }

                if (! $app->get('free-generation-models')) {
                    return [
                        'result' => false,
                        'users_id' => $entity->getId(),
                        'message' => "No free generation models configured in the app settings",
                    ];
                }

                $entity->set('order_credits', $app->get('free-generation-models'));

                return [
                    'result' => true,
                    'users_id' => $entity->getId(),
                    'message' => "Applied referral code to user {$entity->getId()}",
                ];
            },
            company: $company,
        );
    }
}
