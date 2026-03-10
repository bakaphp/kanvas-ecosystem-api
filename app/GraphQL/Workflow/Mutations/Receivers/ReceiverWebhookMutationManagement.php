<?php

declare(strict_types=1);

namespace App\GraphQL\Workflow\Mutations\Receivers;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Actions\CreateReceiverWebhookAction;
use Kanvas\Workflow\Actions\RetryWebhookCallAction;
use Kanvas\Workflow\Actions\UpdateReceiverWebhookAction;
use Kanvas\Workflow\DataTransferObject\ReceiverWebhook as ReceiverWebhookData;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Kanvas\Workflow\Models\WorkflowAction;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class ReceiverWebhookMutationManagement
{
    public function create(mixed $root, array $request): ReceiverWebhook
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        /** @var WorkflowAction $action */
        $action = WorkflowAction::getById((int) $input['action_id']);

        return new CreateReceiverWebhookAction(
            new ReceiverWebhookData(
                app: $app,
                company: $company,
                user: $user,
                action: $action,
                name: $input['name'],
                description: $input['description'] ?? null,
                configuration: $input['configuration'] ?? null,
                is_active: $input['is_active'] ?? true,
                run_async: $input['run_async'] ?? true,
            ),
        )->execute();
    }

    public function update(mixed $root, array $request): ReceiverWebhook
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        /** @var ReceiverWebhook $receiver */
        $receiver = ReceiverWebhook::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        /** @var WorkflowAction $action */
        $action = WorkflowAction::getById((int) ($input['action_id'] ?? $receiver->action_id));

        return new UpdateReceiverWebhookAction(
            $receiver,
            new ReceiverWebhookData(
                app: $app,
                company: $company,
                user: $user,
                action: $action,
                name: $input['name'] ?? $receiver->name,
                description: $input['description'] ?? $receiver->description,
                configuration: $input['configuration'] ?? $receiver->configuration,
                is_active: $input['is_active'] ?? $receiver->is_active,
                run_async: $input['run_async'] ?? $receiver->run_async,
            ),
        )->execute();
    }

    public function delete(mixed $root, array $request): bool
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        /** @var ReceiverWebhook $receiver */
        $receiver = ReceiverWebhook::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        if ($receiver->hasWebhookCalls()) {
            throw new ValidationException('Cannot delete a receiver webhook that has been used. Use force delete with cascade to remove it along with its call history');
        }

        return $receiver->softDelete();
    }

    public function retryWebhookCall(mixed $root, array $args, GraphQLContext $context): bool
    {
        $app = app(Apps::class);
        $receiverWebhookCall = ReceiverWebhookCall::query()->whereRelation(
            'receiverWebhook',
            'apps_id',
            $app->getId()
        )
        ->find($args['id']);

        if (! $receiverWebhookCall) {
            throw new Exception('Webhook call not found');
        }

        $action = new RetryWebhookCallAction($receiverWebhookCall);
        $action->execute();

        return true;
    }
}
