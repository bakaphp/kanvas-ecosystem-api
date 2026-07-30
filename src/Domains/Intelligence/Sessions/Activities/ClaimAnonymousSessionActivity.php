<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Sessions\Actions\ClaimAnonymousSessionAction;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

/**
 * On REGISTERED, if the new user carried an anon_session_token custom field (set by the frontend on
 * register), clone the demo agent into the user's company and copy the demo transcript. The token is
 * consumed (single use). Signups without the field no-op.
 */
#[WorkflowAction]
class ClaimAnonymousSessionActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public const string TOKEN_FIELD = 'anon_session_token';

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        if (! $entity instanceof Users) {
            return ['claimed' => false, 'reason' => 'not_a_user'];
        }

        $token = trim((string) $entity->get(self::TOKEN_FIELD));
        if ($token === '') {
            return ['claimed' => false, 'reason' => 'no_token'];
        }

        $appModel = $app instanceof Apps ? $app : app(Apps::class);

        $session = new ClaimAnonymousSessionAction(
            app: $appModel,
            user: $entity,
            token: $token,
        )->execute();

        $entity->del(self::TOKEN_FIELD);

        return [
            'claimed' => $session !== null,
            'session_uuid' => $session?->uuid,
        ];
    }
}
