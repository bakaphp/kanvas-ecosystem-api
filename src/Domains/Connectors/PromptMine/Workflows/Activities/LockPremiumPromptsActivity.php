<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Souk\Orders\Models\OrderItem;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

class LockPremiumPromptsActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $messageData = ! is_array($entity->message) ? json_decode($entity->message, true) : $entity->message;

        $defaultAppCompanyBranch = $app->get(AppSettingsEnums::GLOBAL_USER_REGISTRATION_ASSIGN_GLOBAL_COMPANY->getValue());

        try {
            $branch = CompaniesBranches::getById($defaultAppCompanyBranch);
            $company = $branch->company;
        } catch (ModelNotFoundException $e) {
            $company = $entity->company;
        }

        if ($messageData['price'] && isset($messageData['price']['sku'])) {
            return [
                'result' => true,
                'message' => 'Message is not a premium prompt, no need to lock',
            ];
        }

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::PROMPT_MINE,
            integrationOperation: function ($entity) use ($messageData) {
                $sku = $messageData['price']['sku'];
                $orderItem = OrderItem::where('product_sku', $sku)
                    ->where('apps_id', $entity->app->getId())
                    ->where('is_deleted', 0)
                    ->first();

                if (! $orderItem) {
                    $entity->is_premium = 0;
                    $entity->setUnlock();

                    return [
                        'result' => false,
                        'message' => 'Message does not have a valid SKU, so we are unlocking it',
                    ];
                }

                $entity->is_premium = 1;
                $entity->setLock();

                return [
                    'message' => 'Message is a premium prompt, locked it',
                    'result' => true,
                    'user_id' => $entity->user->getId(),
                    'message_data' => $entity->message,
                    'message_id' => $entity->getId(),
                ];
            },
            company: $company,
        );
    }
}
