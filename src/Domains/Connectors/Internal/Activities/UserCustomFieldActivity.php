<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Internal\Activities;

use Baka\Contracts\AppInterface;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Workflow\Activity;

class UserCustomFieldActivity extends Activity implements WorkflowActivityInterface
{
    use KanvasJobsTrait;
    public $tries = 10;

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
