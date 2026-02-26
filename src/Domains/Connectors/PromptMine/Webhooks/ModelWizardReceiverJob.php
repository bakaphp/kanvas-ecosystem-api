<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Webhooks;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\PromptMine\Actions\ModelWizardModelChooserAction;
use Override;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;

class ModelWizardReceiverJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $app = app(Apps::class);
        $payload = $this->webhookRequest->payload;
        $this->overwriteAppService($app);

        return (new ModelWizardModelChooserAction(
            $payload,
            auth()->user(),
            $app
        ))->execute();
    }
}
