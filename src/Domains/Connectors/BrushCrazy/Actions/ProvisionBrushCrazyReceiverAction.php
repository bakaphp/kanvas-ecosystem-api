<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Str;
use Kanvas\Connectors\BrushCrazy\Enums\ConfigurationEnum;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Connectors\BrushCrazy\Webhooks\ProcessBrushCrazyWebhookJob;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Actions\CreateReceiverWebhookAction;
use Kanvas\Workflow\DataTransferObject\ReceiverWebhook as ReceiverWebhookData;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;

/**
 * One mirror receiver per studio company. The receiver's company is what tells the webhook job
 * which tenant a pointer belongs to, so a shared receiver could not work.
 *
 * Idempotent and secret-preserving: a studio that already has a receiver keeps its uuid and its
 * signing secret. Minting a new secret on a re-run would silently break every delivery BrushCrazy
 * signs with the one it already holds.
 */
class ProvisionBrushCrazyReceiverAction
{
    public bool $created = false;

    public function __construct(
        protected AppInterface $app,
        protected UserInterface $user,
        protected StudioContext $context,
    ) {
    }

    public function execute(): ReceiverWebhook
    {
        $receiver = $this->existingReceiver();

        if ($receiver !== null) {
            if (empty($receiver->configuration['signing_secret'])) {
                $receiver->configuration = ($receiver->configuration ?? []) + ['signing_secret' => self::newSecret()];
                $receiver->saveOrFail();
            }

            return $receiver;
        }

        $this->created = true;

        // The webhook drops every pointer for a company without this flag. Set only on creation so
        // a re-run of the import never resumes a studio whose mirror an operator paused.
        $this->context->company->set(ConfigurationEnum::BRUSHCRAZY_SYNC_ENABLED->value, true);

        return new CreateReceiverWebhookAction(
            new ReceiverWebhookData(
                app: $this->app,
                company: $this->context->company,
                user: $this->user,
                action: $this->workflowAction(),
                name: 'BrushCrazy Mirror — ' . $this->context->company->name,
                description: 'Entity pointers from BrushCrazy studio ' . $this->context->bcStudioId . '; each one is re-read from the source database.',
                configuration: [
                    'signing_secret' => self::newSecret(),
                    'brushcrazy_studio_id' => $this->context->bcStudioId,
                ],
                is_active: true,
                run_async: true,
            ),
        )->execute();
    }

    protected function existingReceiver(): ?ReceiverWebhook
    {
        return ReceiverWebhook::query()
            ->fromApp($this->app)
            ->fromCompany($this->context->company)
            ->notDeleted()
            ->whereHas(
                'action',
                fn ($query) => $query->where('model_name', ProcessBrushCrazyWebhookJob::class),
            )
            ->first();
    }

    protected function workflowAction(): WorkflowAction
    {
        return WorkflowAction::query()
            ->where('model_name', ProcessBrushCrazyWebhookJob::class)
            ->first()
            ?? throw new ValidationException(
                'The BrushCrazy webhook action is not registered. Run `kanvas:workflow-sync-actions` (it runs on deploy) and retry.'
            );
    }

    private static function newSecret(): string
    {
        return Str::random(64);
    }
}
