<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Internal\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivities;

class UserCustomFieldActivity extends KanvasActivities implements WorkflowActivityInterface
{
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

        return [
            'user_id' => $user->getId(),
            'custom_field' => $customField,
        ];
    }
}
