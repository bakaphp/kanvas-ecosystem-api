<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

class PremiumPromptFlagActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public $tries = 3;

    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);
        $entity->refresh();
        $messageData = $entity->message;
        $defaultAppCompanyBranch = $app->get(AppSettingsEnums::GLOBAL_USER_REGISTRATION_ASSIGN_GLOBAL_COMPANY->getValue());

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
            integrationOperation: function ($entity, $app, $integrationCompany, $additionalParams) use ($messageData) {
                if (! isset($messageData['price']) || ! isset($messageData['price']['sku']) || ! isset($messageData['price']['value'])) {
                    return [
                        'result' => false,
                        'message_id' => $entity->getId(),
                        'message' => 'Not a premium prompt request',
                        'messageData' => $messageData,
                    ];
                }

                if ($entity->isPremium()) {
                    return [
                        'result' => false,
                        'message_id' => $entity->getId(),
                        'message' => 'Prompt already flagged as premium',
                        'messageData' => $messageData,
                    ];
                }

                $entity->setPremium();

                $premiumHash = Str::random(32);
                $usersToNotify = UsersRepository::findUsersByArray($entity->app->get('owner_notification'), $app);
                $notification = new Blank(
                    'premium-request',
                    [
                        'message' => 'New premium prompt flagged',
                        'requested_amount' => $messageData['price'],
                        'title' => ($messageData['title'] ?? 'Untitled') . ' - ID: ' . $entity->getId(),
                        'prompt' => $messageData['prompt'],
                        'messageData' => $messageData,
                        'premiumHash' => $premiumHash,
                    ],
                    ['mail'],
                    $entity,
                );

                $entity->set('premium_approval', false);
                $entity->set('premium_hash', $premiumHash);

                $notification->setSubject('New premium prompt request ' . $entity->getId());
                Notification::send($usersToNotify, $notification);

                return [
                    'result' => true,
                    'message_id' => $entity->getId(),
                    'messageData' => $messageData,
                    'message' => 'Premium prompt flagged - ' . ($messageData['title'] ?? 'Untitled') . ' - ID: ' . $entity->getId(),
                ];
            },
            company: $company,
        );
    }
}
