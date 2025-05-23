<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Workflows\Activities;

use Baka\Contracts\AppInterface;
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

        $messageData = $entity->message;
        $defaultAppCompanyBranch = $app->get(AppSettingsEnums::GLOBAL_USER_REGISTRATION_ASSIGN_GLOBAL_COMPANY->getValue());

        try {
            $branch = CompaniesBranches::getById($defaultAppCompanyBranch);
            $company = $branch->company;
        } catch (ModelNotFoundException $e) {
            $company = $entity->company;
        }

        if (! isset($messageData['price']) || ! isset($messageData['price']['sku']) || ! isset($messageData['price']['price'])) {
            return [
                'result' => false,
                'message_id' => $entity->getId(),
                'message' => 'Not a premium prompt request',
                'messageData' => $messageData,
            ];
        }

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::PROMPT_MINE,
            integrationOperation: function ($entity, $app) use ($messageData) {
                $entity->setPremium();

                $usersToNotify = UsersRepository::findUsersByArray($entity->app->get('owner_notification'), $app);
                $notification = new Blank(
                    'premium-request',
                    [
                        'message' => 'New premium prompt flagged',
                        'requested_amount' => $messageData['price'],
                        'title' => $messageData['title'],
                        'prompt' => $messageData['prompt'],
                        'messageData' => $messageData,
                    ],
                    ['mail'],
                    $entity,
                );

                $notification->setSubject('New premium prompt request');
                Notification::send($usersToNotify, $notification);

                return [
                    'result' => true,
                    'message_id' => $entity->getId(),
                    'messageData' => $messageData,
                    'message' => 'Premium prompt flagged - ' . $messageData['title'],
                ];
            },
            company: $company,
        );
    }
}
