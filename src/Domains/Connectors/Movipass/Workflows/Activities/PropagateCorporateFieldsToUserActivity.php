<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Enums\CorporateLeadFieldEnum;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersInvite;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction]
class PropagateCorporateFieldsToUserActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $user, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $user,
            app: $app,
            integration: IntegrationsEnum::MOVIPASS,
            additionalParams: $params,
            integrationOperation: function ($user, $app, $integrationCompany, $additionalParams) {
                /** @var Users $user */
                $appsModel = $app instanceof Apps ? $app : app(Apps::class);

                // The invite was soft-deleted by ProcessInviteAction; the row still exists.
                $invite = UsersInvite::where('email', $user->email)
                    ->where('apps_id', $appsModel->getId())
                    ->orderByDesc('created_at')
                    ->first();

                if (! $invite || ! (bool) $invite->get('is_corporate')) {
                    return $this->skip($user, $invite ? 'invite is not corporate' : 'no invite found');
                }

                $copied = [];
                foreach (CorporateLeadFieldEnum::USER_FIELDS as $key) {
                    $value = $invite->get($key);
                    if ($value === null || $value === '') {
                        continue;
                    }
                    $user->set($key, $value);
                    $copied[] = $key;
                }

                return [
                    'user' => $user->getId(),
                    'invite' => $invite->getId(),
                    'status' => 'propagated',
                    'fields' => $copied,
                ];
            },
            company: $params['company'] ?? $user->getCurrentCompany(),
        );
    }

    private function skip(Users $user, string $reason): array
    {
        return [
            'user' => $user->getId(),
            'status' => 'skipped',
            'reason' => $reason,
        ];
    }
}
