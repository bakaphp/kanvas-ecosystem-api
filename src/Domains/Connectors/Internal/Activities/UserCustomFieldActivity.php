<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Internal\Activities;

use Baka\Contracts\AppInterface;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction(
    name: 'Set User Custom Fields',
    description: 'Writes custom fields onto a user from values configured on the rule. Does nothing if no '
        . 'fields are configured, so it is inert until you supply them.',
    integration: IntegrationsEnum::INTERNAL,
    params: [
        'customField' => 'A map of field name to {value, is_public}. Without it the step is a no-op.',
    ],
)]
class UserCustomFieldActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $user, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $customField = $params['customField'] ?? null;

        if (! $customField) {
            return ['No custom field configured to set for user'];
        }

        //set custom field to user
        foreach ($customField as $key => $data) {
            $value = $data['value'] ?? null;
            $isPublic = $data['is_public'] ?? false;
            $user->set($key, $value, $isPublic);
        }

        /**
         * @todo remove this, this is so stupid that I can't believe we have this
         */
        $updateUserLastName = false;

        try {
            $userAppProfile = $user->getAppProfile($app);
            if ($userAppProfile->lastname === null && $app->get('dont_force_lastname_default')) {
                $userAppProfile->lastname = '';
                $userAppProfile->update();

                $user->lastname = '';
                $user->update();
                $updateUserLastName = true;
            }
        } catch (Exception $e) {
            //do nothing
        }

        return [
            'user_id' => $user->getId(),
            'custom_field' => $customField,
            'updated_lastname' => $updateUserLastName,
        ];
    }
}
