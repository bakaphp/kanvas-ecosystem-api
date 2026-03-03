<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Webhooks;

use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\PromptMine\Actions\ModelWizardModelChooserAction;
use Kanvas\Users\Models\UsersAssociatedApps;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class ModelWizardReceiverJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $app = app(Apps::class);
        $payload = $this->webhookRequest->payload;
        $this->overwriteAppService($app);

        if (! array_key_exists('model_wizard_answers', $payload) || ! array_key_exists('users_id', $payload)) {
            throw new InvalidArgumentException('Model wizard answers and users_id are required to execute the model wizard receiver job.');
        }

        $userAssoc = UsersAssociatedApps::fromApp($app)
            ->where('users_id', $payload['users_id'])
            ->where('companies_id', 0)
            ->first();
        if (! $userAssoc) {
            throw new InvalidArgumentException('User not found for the provided users_id in the payload.');
        }

        return (new ModelWizardModelChooserAction(
            $payload['model_wizard_answers'],
            $userAssoc->user,
            $app
        ))->execute();
    }
}
